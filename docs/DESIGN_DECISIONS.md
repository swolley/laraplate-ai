# AI Module - Design Decisions

This document explains the reasoning behind key architectural decisions and answers common questions about the module design.

---

## Q: Why do we have both `streamMessage` and `insertMessage`?

### Short Answer

`streamMessage` is the **primary use case** for interactive chat. `insertMessage` was added for completeness but **may be unnecessary** in your application.

### When `streamMessage` is Required

- **Interactive UI**: Users see text appearing in real-time (ChatGPT-like experience)
- **Better UX**: No waiting 10-30 seconds staring at a loading spinner
- **Perceived performance**: Users feel the AI is "thinking" and responding

### When `insertMessage` Would Be Useful


| Use Case          | Why Non-Streaming?                         |
| ----------------- | ------------------------------------------ |
| Background jobs   | Queue workers don't support SSE            |
| API integrations  | External systems expect JSON response      |
| Automated testing | Easier to assert on complete response      |
| Retry logic       | Simpler to retry failed requests           |
| Mobile apps       | Some mobile HTTP clients struggle with SSE |


### Recommendation

**If you don't have any of the above use cases, you can safely remove `insertMessage`.**

The route naming (`/insert/...`) was chosen for consistency with CRUD conventions, but it's misleading since it's really "send message and get AI response".

---

## Q: How does the assistant change data, and who decides?

The assistant never changes data by itself. A write tool (`crud_create_*`, `crud_update_*`, `crud_delete_*`, `crud_bulk_update_*`, `crud_bulk_delete_*`) only stores a **write proposal** (`ai_write_proposals`) and tells the model that nothing has changed. The person confirms or rejects the proposal through an authenticated action the model cannot perform, and only then is the stored payload applied, as that person, through `CrudService`. Whether the write is also sent for a vote is decided by Core approvals at the model (`HasApprovals`), not by the assistant.

**Why the person confirms outside the model.** The assistant is stateless (each `respond()` is one message, no history), so a model cannot carry a confirmation from one turn to the next, and a confirmation the model reads in a message can be influenced by text it retrieved. A proposal is applied only by an authenticated HTTP action of the person it was proposed to. The model cannot perform it, and neither can text it read.

**Why there is no risk level.** An earlier design classified tools by name (`delete_*` high, `update_*` medium, the rest low) and routed them through `ActionRequest`. It had no production caller, read no argument and defaulted to low, and it duplicated the approvals that Core enforces at the model for every surface. It was removed. Whether a write needs a vote is `wouldRequireApproval()` of the model; whether the person is asked is always yes.

**Privileged users.** A superadmin or an `approve` holder writes without a vote everywhere, so the assistant does the same for them once they confirm. What replaces the missing review is the protocol around the assistant: the acting user and their permissions are stated to the model and to the client, the assistant is confined to the application and refuses attempts to change its rules, and no write happens without the person's own confirmation.

**Entities without approvals.** The assistant may write to one only if the operator listed it under `ai.features.tools.crud.unmoderated_writes`: "applied directly, no vote".

**Pending proposals and messages.** A proposal is its own row, not a message; messages carry it in `metadata.writes`. Messages keep flowing while a proposal waits; it expires (`ai.features.tools.crud.proposal_ttl_minutes`, 30 by default) and a client sees `expired` if the person returns too late.

---

## Q: Do streaming messages support tool calling?

### Short Answer: No

LLPhant's streaming API (`generateStreamOfText`) returns text chunks, not function calls.

### Technical Limitation

```php
// Non-streaming: can return FunctionInfo[]
$result = $chat->generateTextOrReturnFunctionCalled($message);

// Streaming: always returns text chunks
foreach ($chat->generateStreamOfText($message) as $chunk) {
    // $chunk is always string
}
```

### Workaround (If Needed)

1. Make non-streaming call to check for tool proposals
2. If tools propose writes, store write proposals
3. Send tool results back to LLM
4. Stream the final response

This would require refactoring `sendMessageStream` significantly.

---

## Q: How does the Memory/Summarization system work?

### Purpose

Prevent context window overflow in long conversations by:

1. Summarizing older messages
2. Extracting key facts
3. Using summary as context for new messages

### Trigger

After **every** message (in `sendMessage` and `sendMessageStream`):

```php
$this->checkAndCreateSummaryIfNeeded($conversation, $chat);
```

### Configuration

```env
AI_CHAT_ENABLE_SUMMARY=true        # Enable the feature
AI_CHAT_SUMMARY_THRESHOLD=20       # Summarize after N messages
```

### Per-Conversation Control

```php
$conversation->memory_enabled = true;  // Enable memory
$conversation->memory_enabled = false; // Disable (also clears existing summary)
```

---

## Q: Can I use the AI module without exposing public APIs?

### Yes - Internal Use Only

The module is designed for:

1. **Event-driven integration** - Embeddings, translations triggered by model events
2. **Job processing** - Background AI tasks
3. **Internal services** - Use `ChatService` directly in your code

### Example: Internal AI Service

```php
class MyService
{
    public function __construct(
        private readonly ChatService $chatService,
    ) {}
    
    public function analyzeContent(Content $content): string
    {
        $conversation = $this->chatService->createConversation(
            user: $content->author,
            systemMessage: 'You are a content analyzer...',
        );
        
        $message = $this->chatService->sendMessage(
            $conversation,
            "Analyze this content: {$content->body}",
        );
        
        return $message->content;
    }
}
```

No HTTP endpoint needed - use services directly.

---

## Summary: Feature Status


| Component              | Status   | Notes                                       |
| ---------------------- | -------- | ------------------------------------------- |
| `streamMessage`        | ✅ Active | Primary chat use case, SSE streaming        |
| `insertMessage`        | ✅ Active | For jobs, APIs, testing - JSON response     |
| `messages-with-tools`  | ✅ Active | Writes are proposals the person confirms     |
| Embedding system       | ✅ Active | Powers vector search                        |
| Translation system     | ✅ Active | Automatic translations                      |
| RAG/FAQ                | ✅ Active | Automatic question detection + answer       |
| Contextual suggestions | ✅ Active | Proactive AI suggestions with rate limiting |
| Memory/Summary         | ✅ Active | Enable via `AI_CHAT_ENABLE_SUMMARY=true`    |
| Guardrails             | ✅ Active | Enable via `AI_GUARDRAILS_ENABLED=true`     |


### Key Design Principles

1. **Event-Driven Integration** - AI module listens to Core events, Core never imports AI
2. **Privacy-First Default** - Ollama as default provider (local processing)
3. **Human-in-the-Loop** - Tool system requires confirmation for medium/high risk actions
4. **Configurable Everything** - All features can be enabled/disabled via env vars
5. **Graceful Degradation** - App works normally when AI module is disabled

