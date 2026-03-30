# AI Blog Writer

A demo WordPress plugin that shows how to consume context from [AI Context Registry](https://github.com/mperalty/ai-cx) to generate AI-powered blog post drafts.

This is **not a production plugin**. It exists to demonstrate how a third-party plugin can integrate with the AI Context Registry's PHP API and the WordPress 7.0 AI Client SDK.

## What It Does

1. You enter a blog topic, optional domain/audience hints, and a tone
2. The plugin retrieves relevant context from AI Context Registry (`aicx_find_context()`)
3. It injects that context into a system prompt and sends it to whichever AI provider the site owner has configured (Anthropic, Google, OpenAI)
4. The AI response becomes a WordPress draft post
5. Usage feedback is recorded back to the registry (`aicx_record_usage()`) so context scoring improves over time

## How It Demonstrates AI Context Registry

### Context Retrieval

```php
$result = aicx_find_context( [
    'query'         => $topic,
    'token_budget'  => $budget,    // 20% of model's context window
    'content_depth' => 'adaptive', // fit more items at compact, upgrade best to summary
    'domains'       => [ 'billing' ],
    'audiences'     => [ 'customers' ],
] );

$items = $result['items'];              // ranked, budget-fitted context
$remaining = $result['remaining_budget']; // tokens left over
```

### Essential Items (Always-Include)

Context items with `manual_priority = 10` are guaranteed to appear in every retrieval call. This is how brand voice, style guides, and must-have context works — the registry reserves budget for them before scoring normal items.

### Two-Pass Retrieval

The plugin tries filtered retrieval first (matching the user's domain/audience inputs). If nothing matches, it retries without filters so broad context like brand voice still gets pulled in.

### Usage Feedback

After generation, the plugin records which context items were used in the prompt:

```php
aicx_record_usage( [
    'events' => [
        [
            'item_id'            => $id,
            'consumer_plugin'    => 'ai-blog-writer',
            'consumer_feature'   => 'post-generation',
            'task_type'          => 'content-generation',
            'was_used_in_prompt' => true,
        ],
    ],
] );
```

This feeds back into the registry's usefulness scoring so frequently-used context ranks higher in future retrievals.

### Dynamic Token Budget

The plugin reserves 20% of the AI model's context window for registry context. Since the WordPress AI Client SDK doesn't expose context window metadata, a lookup table maps known model IDs to their documented limits (e.g., `claude-haiku-4-5` → 200K tokens → 40K token budget).

## Requirements

- WordPress 7.0+
- PHP 8.1+
- [AI Context Registry](https://github.com/mperalty/ai-cx) plugin (active)
- An AI provider plugin configured in Settings > Connectors (e.g., AI Provider for Anthropic)

The plugin degrades gracefully — it shows a warning if AI Context Registry isn't active (generation still works, just without context enrichment) and an error if no AI provider is available.

## Installation

1. Download or clone this repo into `wp-content/plugins/ai-blog-writer/`
2. Activate through the WordPress Plugins screen
3. Go to **Tools > AI Blog Writer**

## Debug Log

Every generation attempt produces a step-by-step debug log showing context retrieval parameters, items found, prompt construction, AI provider response, and draft creation. The log appears as a collapsible section on the plugin page after each attempt.

## License

GPL-2.0-or-later
