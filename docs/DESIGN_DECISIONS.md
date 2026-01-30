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

| Use Case | Why Non-Streaming? |
|----------|-------------------|
| Background jobs | Queue workers don't support SSE |
| API integrations | External systems expect JSON response |
| Automated testing | Easier to assert on complete response |
| Retry logic | Simpler to retry failed requests |
| Mobile apps | Some mobile HTTP clients struggle with SSE |

### Recommendation

**If you don't have any of the above use cases, you can safely remove `insertMessage`.**

The route naming (`/insert/...`) was chosen for consistency with CRUD conventions, but it's misleading since it's really "send message and get AI response".

---

## Q: What is the Tool System and when are ActionRequests created?

### Overview

The Tool System allows AI to propose **actions** that modify the system. Instead of the AI directly executing code, it proposes tool calls that go through approval workflows.

### When ActionRequests Are Created

ActionRequests are created **only** when:

1. A user sends a message via `ChatService::sendMessageWithTools()`
2. The LLM responds with tool calls (not just text)
3. For each proposed tool, an `ActionRequest` is created

**Currently, this never happens** because `sendMessageWithTools()` is not exposed via any API endpoint.

### The 3-Level Risk System

```
┌─────────────────────────────────────────────────────────────────┐
│  User: "Create a new blog post titled 'Hello World'"            │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  LLM proposes: create_content(title="Hello World", body="...")  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  RiskClassifier evaluates: "create_content" → medium risk       │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  ActionRequest created:                                          │
│    status: pending_user_confirmation                             │
│    tool_name: create_content                                     │
│    tool_args: {title: "Hello World", body: "..."}               │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  UI shows: "AI wants to create a blog post. Confirm?"           │
│            [Confirm] [Reject]                                    │
└─────────────────────────────────────────────────────────────────┘
                              │
                    User clicks [Confirm]
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  ActionRequestService::confirmRequest()                          │
│    → status: approved                                            │
│    → ExecuteActionRequestJob dispatched                          │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  Job executes handler: Content::create([...])                    │
│    → status: completed                                           │
│    → result: {id: 123, title: "Hello World", ...}               │
└─────────────────────────────────────────────────────────────────┘
```

### Risk Levels Explained

| Level | Status After Creation | User Action | Example Tools |
|-------|----------------------|-------------|---------------|
| `low` | `approved` | None (auto-execute) | Read data, search, format text |
| `medium` | `pending_user_confirmation` | User confirms | Create content, send email |
| `high` | `pending_admin_approval` | Admin approves | Delete data, change settings, financial |

### Why Isn't It Exposed Yet?

The tool system requires:

1. **Frontend UI** - Confirmation dialogs, action history
2. **Admin panel** - Approval interface for high-risk actions
3. **Tool definitions** - Registering actual tools to use
4. **Security review** - Ensuring AI can't be tricked into harmful actions

It was built as infrastructure for future features, not for immediate use.

---

## Q: Should ActionRequests appear in the conversation as messages?

### Current Design: No

ActionRequests are **separate entities** from Messages:
- `ai_messages` table: conversation history
- `ai_action_requests` table: tool execution audit log

### Alternative Design: Yes (Not Implemented)

You could show tool executions as conversation messages:

```
User: Create a blog post about cats
Assistant: I'll create that for you.
           ⚠️ Action requested: Create Blog Post
           [Confirm] [Reject]
User: [Confirmed]
Assistant: ✅ Created blog post #123: "All About Cats"
```

This would require:
1. Adding action results as new messages
2. Real-time UI updates when actions complete
3. Showing action status in message metadata

### Recommendation

Keep them separate for now. ActionRequests can be linked to conversations via `conversation_id`, but their results don't need to be messages unless you want conversational audit trail.

---

## Q: What happens to messages sent while an ActionRequest is pending?

### Current Behavior

Messages continue to flow normally. ActionRequests are independent:

```
Message 1: User asks to create content
Message 2: AI proposes tool (ActionRequest created)
Message 3: User asks another question    ← works fine
Message 4: AI responds                   ← works fine
           (ActionRequest still pending)
Message 5: User confirms action          ← via separate endpoint
           (ActionRequest executes)
```

### This Allows

- **Non-blocking chat**: Users can continue chatting while actions are pending
- **Asynchronous approval**: Admin can approve high-risk actions hours later
- **Multiple pending actions**: Several actions can be awaiting approval simultaneously

---

## Q: Why is `sendMessageWithTools` a separate method from `sendMessage`?

### Reason: Different Response Structures

| Method | Returns | Use Case |
|--------|---------|----------|
| `sendMessage` | `Message` | Simple chat, RAG-enabled Q&A |
| `sendMessageWithTools` | `{message, action_requests[]}` | AI agents with tool capabilities |

### Why Not Merge Them?

1. **Performance**: Tool-enabled chat requires loading tool definitions, setting them on the chat instance
2. **Complexity**: Most chats don't need tools - simpler path for simpler use case
3. **Explicit intent**: Caller knows if they want tool capabilities

### Alternative: Single Method with Flag

```php
public function sendMessage(
    Conversation $conversation,
    string $message,
    ?array $context = null,
    bool $withTools = false,  // flag
): Message|array {
    if ($withTools) {
        return $this->sendMessageWithTools($conversation, $message, $context);
    }
    // ... normal flow
}
```

This was not done to keep return types consistent (`Message` vs `array`).

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
2. If tools proposed, create ActionRequests
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

| Component | Status | Notes |
|-----------|--------|-------|
| `streamMessage` | ✅ Active | Primary chat use case, SSE streaming |
| `insertMessage` | ✅ Active | For jobs, APIs, testing - JSON response |
| `sendMessageWithTools` | 🔮 Ready | Infrastructure complete, needs API exposure |
| ActionRequest system | 🔮 Ready | Infrastructure complete, needs API exposure |
| Embedding system | ✅ Active | Powers vector search |
| Translation system | ✅ Active | Automatic translations |
| RAG/FAQ | ✅ Active | Automatic question detection + answer |
| Contextual suggestions | ✅ Active | Proactive AI suggestions with rate limiting |
| Memory/Summary | ✅ Active | Enable via `AI_CHAT_ENABLE_SUMMARY=true` |
| Guardrails | ✅ Active | Enable via `AI_GUARDRAILS_ENABLED=true` |

### Key Design Principles

1. **Event-Driven Integration** - AI module listens to Core events, Core never imports AI
2. **Privacy-First Default** - Ollama as default provider (local processing)
3. **Human-in-the-Loop** - Tool system requires confirmation for medium/high risk actions
4. **Configurable Everything** - All features can be enabled/disabled via env vars
5. **Graceful Degradation** - App works normally when AI module is disabled
