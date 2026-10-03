<?php
// Chat Completions parameters that differ between OpenAI model generations.
//
// Older chat models (gpt-4o, gpt-4o-mini, gpt-4.1) and reasoning models
// (o-series, gpt-5 and later) disagree on three request fields:
//
//   - Output cap. Reasoning models reject the legacy 'max_tokens' and require
//     'max_completion_tokens'. Every current chat model, gpt-4o-mini included,
//     accepts 'max_completion_tokens', so it is always sent.
//   - reasoning_effort. Only reasoning models accept it; older chat models
//     reject it. Every call here is a short, latency-sensitive task with no
//     multi-step problem to work through, so reasoning is turned off (or as
//     low as the model allows). Reasoning tokens also count against the
//     output cap, so leaving it at the model's default could spend the whole
//     cap thinking and return an empty answer.
//   - temperature. Reasoning models accept it only with reasoning turned off.
//
// Only the model name is known at request time, so the family is read from it.

// The effort to send for $model, or null when the model takes no
// reasoning_effort at all.
function openAIReasoningEffort(string $model): ?string {
    $m = strtolower(trim($model));
    // o1, o3, o4-mini, ...: no way to switch reasoning off; 'low' is the floor.
    if (preg_match('/^o\d/', $m)) return 'low';
    if (preg_match('/^gpt-(\d+)(?:\.(\d+))?/', $m, $g) && (int)$g[1] >= 5) {
        // gpt-5-chat-latest and similar '-chat' variants are non-reasoning.
        if (strpos($m, '-chat') !== false) return null;
        // The original gpt-5 / -mini / -nano predate 'none'; 'minimal' is
        // their floor. gpt-5.1 and later (gpt-6-luna included) accept 'none'.
        if ((int)$g[1] === 5 && (int)($g[2] ?? 0) === 0) return 'minimal';
        return 'none';
    }
    return null;
}

// Fields to merge into a Chat Completions payload for $model. $maxTokens null
// leaves the output cap at the model's default.
function openAIChatOptions(string $model, ?int $maxTokens, float $temperature): array {
    $opts   = [];
    $effort = openAIReasoningEffort($model);
    if ($effort !== null) $opts['reasoning_effort'] = $effort;
    if ($effort === null || $effort === 'none') $opts['temperature'] = $temperature;
    if ($maxTokens !== null) $opts['max_completion_tokens'] = $maxTokens;
    return $opts;
}
