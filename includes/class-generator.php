<?php

namespace AI_Blog_Writer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blog post generator that uses AI Context Registry + WP AI Client SDK.
 *
 * Flow:
 * 1. Admin enters a topic and optional domain/audience hints.
 * 2. Plugin calls aicx_find_context() to retrieve relevant context.
 * 3. Plugin builds a prompt with that context and sends it to the AI Client SDK.
 * 4. AI response becomes a draft post.
 */
class Generator {

	/** Admin page slug. */
	private const PAGE_SLUG = 'ai-blog-writer';

	/** Nonce action. */
	private const NONCE_ACTION = 'aibw_generate';

	/** Fraction of the model's context window to reserve for AICX context. */
	private const CONTEXT_BUDGET_RATIO = 0.20;

	/** Fallback token budget when the model's context window is unknown. */
	private const CONTEXT_BUDGET_FALLBACK = 8000;

	/** Transient key for debug log. */
	private const DEBUG_TRANSIENT = 'aibw_debug_log';

	/**
	 * Model preferences for text generation.
	 *
	 * The SDK evaluates these in order and uses the first one available
	 * via a configured provider plugin.
	 */
	private const MODEL_PREFERENCES = [
		[ 'anthropic', 'claude-haiku-4-5' ],
		[ 'anthropic', 'claude-sonnet-4-5' ],
		[ 'google', 'gemini-2.5-flash' ],
		[ 'openai', 'gpt-4o-mini' ],
		[ 'openai', 'gpt-4.1' ],
	];

	/**
	 * Known context window sizes (input tokens) for common models.
	 *
	 * The WP AI Client SDK does not expose context window metadata,
	 * so we maintain a lookup. Values from provider documentation.
	 * Models not listed here get the fallback budget.
	 *
	 * @var array<string, int>
	 */
	private const MODEL_CONTEXT_WINDOWS = [
		// Anthropic.
		'claude-haiku-4-5'        => 200000,
		'claude-sonnet-4-5'       => 200000,
		'claude-sonnet-4-5-20250514' => 200000,
		'claude-opus-4'           => 200000,
		'claude-opus-4-6'         => 1000000,
		// Google.
		'gemini-2.5-flash'        => 1048576,
		'gemini-2.5-pro'          => 1048576,
		// OpenAI.
		'gpt-4o-mini'             => 128000,
		'gpt-4o'                  => 128000,
		'gpt-4.1'                 => 1047576,
		'gpt-4.1-mini'            => 1047576,
		'gpt-4.1-nano'            => 1047576,
	];

	/**
	 * Request timeout in seconds.
	 *
	 * Blog post generation is long-form. The Anthropic API can take 30-60s
	 * for a full blog post. We also need to defeat WordPress/cURL low-speed
	 * limits which kill connections that transfer < 1KB/sec for 30s — common
	 * with streaming LLM responses that take time before first byte.
	 */
	private const REQUEST_TIMEOUT = 120;

	/** @var array Debug log entries for the current request. */
	private array $debug_log = [];

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'handle_submission' ] );
	}

	/**
	 * Add the admin menu page.
	 */
	public function register_menu(): void {
		add_management_page(
			__( 'AI Blog Writer', 'ai-blog-writer' ),
			__( 'AI Blog Writer', 'ai-blog-writer' ),
			'publish_posts',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	// =========================================================================
	// Debug logging.
	// =========================================================================

	private function log( string $step, string $message, mixed $data = null ): void {
		$entry = [
			'time'    => gmdate( 'H:i:s' ),
			'step'    => $step,
			'message' => $message,
		];

		if ( null !== $data ) {
			$entry['data'] = $data;
		}

		$this->debug_log[] = $entry;
	}

	private function save_debug_log(): void {
		set_transient( self::DEBUG_TRANSIENT, $this->debug_log, 300 );
	}

	// =========================================================================
	// Page rendering.
	// =========================================================================

	public function render_page(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI Blog Writer', 'ai-blog-writer' ) . '</h1>';

		$this->render_dependency_notices();
		$this->render_result_notices();
		$this->render_debug_log();
		$this->render_form();

		echo '</div>';
	}

	private function render_dependency_notices(): void {
		if ( ! function_exists( 'aicx_find_context' ) ) {
			echo '<div class="notice notice-warning"><p>';
			esc_html_e(
				'AI Context Registry is not active. The writer will still work, but without context enrichment.',
				'ai-blog-writer'
			);
			echo '</p></div>';
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			echo '<div class="notice notice-error"><p>';
			esc_html_e(
				'The WordPress AI Client SDK is not available. Please use WordPress 7.0+ with a configured AI provider.',
				'ai-blog-writer'
			);
			echo '</p></div>';
		}
	}

	private function render_result_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['aibw_draft_id'] ) ) {
			$draft_id = absint( $_GET['aibw_draft_id'] );
			$edit_url = get_edit_post_link( $draft_id, 'raw' );
			printf(
				'<div class="notice notice-success"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Draft created!', 'ai-blog-writer' ),
				esc_url( $edit_url ),
				esc_html__( 'Edit draft', 'ai-blog-writer' )
			);
		}

		$error_message = get_transient( 'aibw_error_message' );
		if ( false !== $error_message ) {
			delete_transient( 'aibw_error_message' );
			echo '<div class="notice notice-error"><p>' . esc_html( $error_message ) . '</p></div>';
		}
	}

	private function render_debug_log(): void {
		$log = get_transient( self::DEBUG_TRANSIENT );

		if ( empty( $log ) || ! is_array( $log ) ) {
			return;
		}

		delete_transient( self::DEBUG_TRANSIENT );

		echo '<details style="max-width:900px;margin-bottom:20px;">';
		echo '<summary style="cursor:pointer;font-weight:600;padding:8px 0;">' . esc_html__( 'Debug Log (last attempt)', 'ai-blog-writer' ) . '</summary>';
		echo '<table class="widefat striped" style="margin-top:8px;"><thead><tr>';
		echo '<th style="width:70px;">' . esc_html__( 'Time', 'ai-blog-writer' ) . '</th>';
		echo '<th style="width:80px;">' . esc_html__( 'Step', 'ai-blog-writer' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'ai-blog-writer' ) . '</th>';
		echo '<th>' . esc_html__( 'Data', 'ai-blog-writer' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $log as $entry ) {
			$is_error = str_contains( $entry['message'] ?? '', 'FAILED' ) || str_contains( $entry['message'] ?? '', 'error' );
			$style    = $is_error ? ' style="background:#fef0f0;"' : '';

			echo "<tr{$style}>";
			echo '<td><code>' . esc_html( $entry['time'] ?? '' ) . '</code></td>';
			echo '<td><strong>' . esc_html( $entry['step'] ?? '' ) . '</strong></td>';
			echo '<td>' . esc_html( $entry['message'] ?? '' ) . '</td>';
			echo '<td>';
			if ( isset( $entry['data'] ) ) {
				echo '<pre style="margin:0;max-height:150px;overflow:auto;font-size:11px;white-space:pre-wrap;word-break:break-all;">';
				echo esc_html( is_string( $entry['data'] ) ? $entry['data'] : wp_json_encode( $entry['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				echo '</pre>';
			} else {
				echo '&mdash;';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</details>';
	}

	private function render_form(): void {
		echo '<form method="post" id="aibw-form" style="max-width:720px;">';
		wp_nonce_field( self::NONCE_ACTION, 'aibw_nonce' );
		echo '<input type="hidden" name="aibw_action" value="generate" />';

		echo '<table class="form-table" role="presentation"><tbody>';

		// Topic.
		echo '<tr>';
		echo '<th scope="row"><label for="aibw_topic">' . esc_html__( 'Topic', 'ai-blog-writer' ) . '</label></th>';
		echo '<td>';
		echo '<input type="text" name="aibw_topic" id="aibw_topic" class="large-text" placeholder="' .
			esc_attr__( 'e.g. How to handle refund requests quickly', 'ai-blog-writer' ) . '" required />';
		echo '</td></tr>';

		// Domain hint.
		echo '<tr>';
		echo '<th scope="row"><label for="aibw_domain">' . esc_html__( 'Domain (optional)', 'ai-blog-writer' ) . '</label></th>';
		echo '<td>';
		echo '<input type="text" name="aibw_domain" id="aibw_domain" class="regular-text" placeholder="' .
			esc_attr__( 'e.g. billing, support, product', 'ai-blog-writer' ) . '" />';
		echo '<p class="description">' . esc_html__( 'Narrows context to a specific domain. Leave empty to match all.', 'ai-blog-writer' ) . '</p>';
		echo '</td></tr>';

		// Audience hint.
		echo '<tr>';
		echo '<th scope="row"><label for="aibw_audience">' . esc_html__( 'Audience (optional)', 'ai-blog-writer' ) . '</label></th>';
		echo '<td>';
		echo '<input type="text" name="aibw_audience" id="aibw_audience" class="regular-text" placeholder="' .
			esc_attr__( 'e.g. customers, developers, internal', 'ai-blog-writer' ) . '" />';
		echo '</td></tr>';

		// Tone.
		echo '<tr>';
		echo '<th scope="row"><label for="aibw_tone">' . esc_html__( 'Tone', 'ai-blog-writer' ) . '</label></th>';
		echo '<td>';
		echo '<select name="aibw_tone" id="aibw_tone">';
		$tones = [
			'professional' => __( 'Professional', 'ai-blog-writer' ),
			'casual'       => __( 'Casual', 'ai-blog-writer' ),
			'technical'    => __( 'Technical', 'ai-blog-writer' ),
			'friendly'     => __( 'Friendly', 'ai-blog-writer' ),
		];
		foreach ( $tones as $value => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $value ), esc_html( $label ) );
		}
		echo '</select>';
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<p class="submit">';
		echo '<button type="submit" id="aibw-submit" class="button button-primary">';
		echo esc_html__( 'Generate Draft', 'ai-blog-writer' );
		echo '</button>';
		echo '<span id="aibw-spinner" class="spinner" style="float:none;margin-left:8px;"></span>';
		echo '<span id="aibw-status" style="display:none;margin-left:12px;color:#666;font-style:italic;"></span>';
		echo '</p>';
		echo '</form>';

		?>
		<script>
		(function() {
			var form = document.getElementById('aibw-form');
			var btn = document.getElementById('aibw-submit');
			var spinner = document.getElementById('aibw-spinner');
			var status = document.getElementById('aibw-status');
			if (!form || !btn) return;

			form.addEventListener('submit', function() {
				btn.disabled = true;
				btn.textContent = '<?php echo esc_js( __( 'Generating...', 'ai-blog-writer' ) ); ?>';
				spinner.classList.add('is-active');
				status.style.display = 'inline';

				var steps = [
					{ delay: 0,     text: '<?php echo esc_js( __( 'Retrieving context from registry...', 'ai-blog-writer' ) ); ?>' },
					{ delay: 2000,  text: '<?php echo esc_js( __( 'Building prompt and calling AI provider...', 'ai-blog-writer' ) ); ?>' },
					{ delay: 15000, text: '<?php echo esc_js( __( 'Waiting for AI response (this can take 30-60s for a full post)...', 'ai-blog-writer' ) ); ?>' },
					{ delay: 45000, text: '<?php echo esc_js( __( 'Still waiting — long-form content takes time...', 'ai-blog-writer' ) ); ?>' },
					{ delay: 90000, text: '<?php echo esc_js( __( 'This is taking longer than usual. The request will timeout at 2 minutes.', 'ai-blog-writer' ) ); ?>' },
				];

				steps.forEach(function(step) {
					setTimeout(function() { status.textContent = step.text; }, step.delay);
				});
			});
		})();
		</script>
		<?php
	}

	// =========================================================================
	// Form handling.
	// =========================================================================

	public function handle_submission(): void {
		if ( ! isset( $_POST['aibw_action'] ) || 'generate' !== $_POST['aibw_action'] ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, 'aibw_nonce' );

		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to generate posts.', 'ai-blog-writer' ) );
		}

		$topic    = isset( $_POST['aibw_topic'] ) ? sanitize_text_field( wp_unslash( $_POST['aibw_topic'] ) ) : '';
		$domain   = isset( $_POST['aibw_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['aibw_domain'] ) ) : '';
		$audience = isset( $_POST['aibw_audience'] ) ? sanitize_text_field( wp_unslash( $_POST['aibw_audience'] ) ) : '';
		$tone     = isset( $_POST['aibw_tone'] ) ? sanitize_text_field( wp_unslash( $_POST['aibw_tone'] ) ) : 'professional';

		$this->log( 'start', 'Generation started', [
			'topic'    => $topic,
			'domain'   => $domain,
			'audience' => $audience,
			'tone'     => $tone,
		] );

		if ( '' === $topic ) {
			$this->fail( __( 'Please enter a topic.', 'ai-blog-writer' ) );
			return;
		}

		// Step 1: Retrieve context from AICX.
		$context_block = $this->retrieve_context( $topic, $domain, $audience );

		// Step 2: Generate content via AI Client SDK.
		$result = $this->generate_content( $topic, $tone, $context_block );

		if ( is_wp_error( $result ) ) {
			$this->log( 'ai', 'AI generation FAILED', [
				'error_code'    => $result->get_error_code(),
				'error_message' => $result->get_error_message(),
				'error_data'    => $result->get_error_data(),
			] );
			$this->fail( sprintf(
				__( 'AI generation failed [%1$s]: %2$s', 'ai-blog-writer' ),
				$result->get_error_code(),
				$result->get_error_message()
			) );
			return;
		}

		$this->log( 'ai', 'AI generation succeeded', [
			'content_length'  => strlen( $result['content'] ),
			'content_preview' => mb_substr( strip_tags( $result['content'] ), 0, 200 ) . '...',
		] );

		// Step 3: Create draft post.
		$post_id = $this->create_draft( $topic, $result['content'] );

		if ( is_wp_error( $post_id ) ) {
			$this->log( 'draft', 'Draft creation FAILED', [
				'error' => $post_id->get_error_message(),
			] );
			$this->fail( sprintf(
				__( 'Failed to create draft: %s', 'ai-blog-writer' ),
				$post_id->get_error_message()
			) );
			return;
		}

		$this->log( 'draft', 'Draft created', [ 'post_id' => $post_id ] );

		// Step 4: Record usage feedback.
		$this->record_usage( $result['context_item_ids'] ?? [] );

		$this->log( 'done', 'Complete' );
		$this->save_debug_log();

		wp_safe_redirect( add_query_arg(
			[
				'page'          => self::PAGE_SLUG,
				'aibw_draft_id' => $post_id,
			],
			admin_url( 'tools.php' )
		) );
		exit;
	}

	// =========================================================================
	// Context retrieval.
	// =========================================================================

	/**
	 * Retrieve context from AI Context Registry.
	 *
	 * Strategy: try with the user's facet filters first. If that returns nothing,
	 * retry without facet filters so broad context (like brand voice) still gets
	 * pulled in. The topic query still provides relevance via keyword scoring.
	 */
	private function retrieve_context( string $topic, string $domain, string $audience ): array {
		if ( ! function_exists( 'aicx_find_context' ) ) {
			$this->log( 'context', 'aicx_find_context() not available, skipping' );
			return [ 'text' => '', 'item_ids' => [] ];
		}

		$token_budget = $this->compute_context_budget();

		$base_args = [
			'query'         => $topic,
			'token_budget'  => $token_budget,
			'content_depth' => 'adaptive',
		];

		// Build the filtered version.
		$filtered_args = $base_args;
		$has_filters   = false;

		if ( '' !== $domain ) {
			$filtered_args['domains'] = [ $domain ];
			$has_filters = true;
		}

		if ( '' !== $audience ) {
			$filtered_args['audiences'] = [ $audience ];
			$has_filters = true;
		}

		// Attempt 1: With filters (if any were specified).
		if ( $has_filters ) {
			$this->log( 'context', 'Attempt 1: filtered retrieval', $filtered_args );
			$result = aicx_find_context( $filtered_args );
			$items  = $result['items'] ?? [];

			$this->log( 'context', 'Filtered result', [
				'item_count'       => count( $items ),
				'remaining_budget' => $result['remaining_budget'] ?? null,
			] );

			if ( ! empty( $items ) ) {
				return $this->format_context( $items );
			}

			$this->log( 'context', 'No items matched filters, retrying without facet filters' );
		}

		// Attempt 2: Without facet filters — let keyword scoring do the work.
		$this->log( 'context', 'Unfiltered retrieval (all active items)', $base_args );
		$result = aicx_find_context( $base_args );
		$items  = $result['items'] ?? [];

		$this->log( 'context', 'Unfiltered result', [
			'item_count'       => count( $items ),
			'remaining_budget' => $result['remaining_budget'] ?? null,
		] );

		if ( empty( $items ) ) {
			$this->log( 'context', 'No context items found in registry' );
			return [ 'text' => '', 'item_ids' => [] ];
		}

		return $this->format_context( $items );
	}

	/**
	 * Format retrieved context items into a text block for the prompt.
	 *
	 * @param array $items Items from aicx_find_context().
	 * @return array{text: string, item_ids: int[]}
	 */
	private function format_context( array $items ): array {
		$parts    = [];
		$item_ids = [];

		foreach ( $items as $item ) {
			$id    = (int) ( $item['id'] ?? 0 );
			$title = $item['title'] ?? '';
			$body  = $item['content'] ?? '';

			if ( $id > 0 ) {
				$item_ids[] = $id;
			}

			if ( '' !== $title && '' !== $body ) {
				$parts[] = "### {$title}\n{$body}";
			} elseif ( '' !== $body ) {
				$parts[] = $body;
			}
		}

		$text = ! empty( $parts )
			? "## Relevant Context\n\n" . implode( "\n\n---\n\n", $parts )
			: '';

		$this->log( 'context', 'Formatted context block', [
			'item_ids'    => $item_ids,
			'text_length' => strlen( $text ),
			'text_preview' => mb_substr( $text, 0, 300 ),
		] );

		return [ 'text' => $text, 'item_ids' => $item_ids ];
	}

	/**
	 * Compute the token budget for context retrieval.
	 *
	 * Reserves CONTEXT_BUDGET_RATIO (20%) of the model's context window for
	 * AICX context. Uses a known lookup table since the WP AI Client SDK
	 * does not expose context window metadata.
	 *
	 * @return int Token budget for aicx_find_context().
	 */
	private function compute_context_budget(): int {
		// Walk the model preference list and use the context window of the
		// first model we recognize. This matches the SDK's own selection
		// logic — it picks the first available model in the preference list.
		foreach ( self::MODEL_PREFERENCES as [ $provider, $model_id ] ) {
			if ( isset( self::MODEL_CONTEXT_WINDOWS[ $model_id ] ) ) {
				$window = self::MODEL_CONTEXT_WINDOWS[ $model_id ];
				$budget = (int) floor( $window * self::CONTEXT_BUDGET_RATIO );

				$this->log( 'budget', 'Context budget computed from model', [
					'model'          => "{$provider}/{$model_id}",
					'context_window' => $window,
					'ratio'          => self::CONTEXT_BUDGET_RATIO,
					'token_budget'   => $budget,
				] );

				return $budget;
			}
		}

		$this->log( 'budget', 'No known model found, using fallback budget', [
			'token_budget' => self::CONTEXT_BUDGET_FALLBACK,
		] );

		return self::CONTEXT_BUDGET_FALLBACK;
	}

	// =========================================================================
	// AI generation.
	// =========================================================================

	private function generate_content( string $topic, string $tone, array $context_block ): array|\WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new \WP_Error( 'no_ai_client', 'wp_ai_client_prompt() not found. Is this WordPress 7.0+?' );
		}

		// Build system instruction.
		$system_parts = [
			"You are a skilled blog writer. Write in a {$tone} tone.",
			'Write a well-structured blog post with an engaging introduction, clear sections with headings, and a conclusion.',
			'Use HTML suitable for the WordPress block editor: <h2> for section headings, <p> for paragraphs, <ul>/<ol> for lists.',
			'Do not include the post title — it will be set separately.',
		];

		if ( '' !== $context_block['text'] ) {
			$system_parts[] = "Use the following context to inform and ground your writing. Reference specific details where relevant:\n\n" . $context_block['text'];
		}

		$system_instruction = implode( "\n\n", $system_parts );

		$prompt = sprintf(
			"Write a blog post about: %s\n\nRespond with ONLY the post body HTML.",
			$topic
		);

		$this->log( 'ai', 'Prompt built', [
			'prompt_length'     => strlen( $prompt ),
			'system_length'     => strlen( $system_instruction ),
			'context_injected'  => '' !== $context_block['text'],
			'timeout'           => self::REQUEST_TIMEOUT,
			'model_preferences' => self::MODEL_PREFERENCES,
		] );

		// Hook into cURL to disable low-speed limits and set a generous timeout.
		// WordPress/cURL enforces CURLOPT_LOW_SPEED_LIMIT (1 byte/sec) and
		// CURLOPT_LOW_SPEED_TIME (30s) by default, which kills LLM requests
		// that have high time-to-first-byte.
		add_action( 'http_api_curl', [ $this, 'configure_curl' ], 10, 3 );

		// Also override the SDK's constructor-level timeout via the WP filter.
		add_filter( 'wp_ai_client_default_request_timeout', [ $this, 'get_request_timeout' ] );

		$this->log( 'ai', 'Sending request to AI provider...' );

		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system_instruction )
			->using_max_tokens( 2000 )
			->using_model_preference( ...self::MODEL_PREFERENCES );

		$is_supported = $builder->is_supported_for_text_generation();

		$this->log( 'ai', 'Support check', [ 'is_supported' => $is_supported ] );

		if ( ! $is_supported ) {
			$this->cleanup_hooks();
			return new \WP_Error(
				'ai_not_supported',
				'No AI provider supports text generation. Check Settings > Connectors and ensure a provider plugin is active with a valid API key.'
			);
		}

		$content = $builder->generate_text();

		$this->cleanup_hooks();

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		return [
			'content'          => $content,
			'context_item_ids' => $context_block['item_ids'],
		];
	}

	/**
	 * Configure cURL handle for LLM requests.
	 *
	 * LLM APIs have high time-to-first-byte (the model is thinking) followed
	 * by fast streaming. The default WordPress/cURL low-speed settings kill
	 * the connection during the thinking phase. We disable that and set a
	 * single generous CURLOPT_TIMEOUT instead.
	 *
	 * @param resource|\CurlHandle $handle  cURL handle.
	 * @param array                $parsed_args WP HTTP request args.
	 * @param string               $url     Request URL.
	 */
	public function configure_curl( $handle, array $parsed_args, string $url ): void {
		// Only modify requests to known AI provider endpoints.
		$ai_hosts = [ 'api.anthropic.com', 'generativelanguage.googleapis.com', 'api.openai.com' ];
		$host     = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! in_array( $host, $ai_hosts, true ) ) {
			return;
		}

		$this->log( 'curl', "Configuring cURL for {$host}", [
			'timeout'          => self::REQUEST_TIMEOUT,
			'low_speed_limit'  => 0,
			'low_speed_time'   => 0,
		] );

		// Generous overall timeout.
		curl_setopt( $handle, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT );
		curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 15 );

		// Disable low-speed detection — LLMs have high TTFB.
		curl_setopt( $handle, CURLOPT_LOW_SPEED_LIMIT, 0 );
		curl_setopt( $handle, CURLOPT_LOW_SPEED_TIME, 0 );
	}

	/**
	 * Filter callback for the SDK constructor-level timeout.
	 */
	public function get_request_timeout(): int {
		return self::REQUEST_TIMEOUT;
	}

	/**
	 * Remove our hooks after the AI call.
	 */
	private function cleanup_hooks(): void {
		remove_action( 'http_api_curl', [ $this, 'configure_curl' ], 10 );
		remove_filter( 'wp_ai_client_default_request_timeout', [ $this, 'get_request_timeout' ] );
	}

	// =========================================================================
	// Post creation.
	// =========================================================================

	private function create_draft( string $topic, string $content ): int|\WP_Error {
		return wp_insert_post( [
			'post_title'   => $topic,
			'post_content' => wp_kses_post( $content ),
			'post_status'  => 'draft',
			'post_author'  => get_current_user_id(),
			'meta_input'   => [
				'_aibw_generated' => true,
				'_aibw_source'    => 'ai-blog-writer',
			],
		], true );
	}

	// =========================================================================
	// Usage feedback.
	// =========================================================================

	private function record_usage( array $item_ids ): void {
		if ( ! function_exists( 'aicx_record_usage' ) || empty( $item_ids ) ) {
			return;
		}

		$events = [];

		foreach ( $item_ids as $item_id ) {
			$events[] = [
				'item_id'            => $item_id,
				'consumer_plugin'    => 'ai-blog-writer',
				'consumer_feature'   => 'post-generation',
				'task_type'          => 'content-generation',
				'was_used_in_prompt' => true,
			];
		}

		aicx_record_usage( [ 'events' => $events ] );
		$this->log( 'usage', 'Recorded usage for ' . count( $item_ids ) . ' context items' );
	}

	// =========================================================================
	// Error handling.
	// =========================================================================

	private function fail( string $message ): void {
		$this->save_debug_log();
		set_transient( 'aibw_error_message', $message, 120 );

		wp_safe_redirect( add_query_arg(
			[ 'page' => self::PAGE_SLUG ],
			admin_url( 'tools.php' )
		) );
		exit;
	}
}
