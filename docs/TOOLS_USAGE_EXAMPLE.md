# sendMessageWithTools – Example Usage

This document shows how `sendMessageWithTools` is used end-to-end and why it is **non-streaming**.

---

## Why Non-Streaming?


| Aspect                | Explanation                                                                                                                                                                                        |
| --------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **LLM response type** | The LLM returns either **plain text** or **tool calls** (function invocations). Tool calls are a structured payload, not a stream of tokens.                                                       |
| **API design**        | `generateTextOrReturnFunctionCalled()` returns either `string` or `FunctionInfo[]` — a complete response, not a stream.                                                                            |
| **User flow**         | After the response you may need to show **confirmation UI** (e.g. "AI wants to create X. Confirm?"). That requires a full JSON response with `message` + `action_requests`.                        |
| **Streaming + tools** | Streaming tool calls would require a different protocol (e.g. stream chunks that indicate "tool_call_start", "tool_call_args", "tool_call_end"). Not supported by the current LLPhant/OpenAI flow. |


So: **use streaming for plain chat** (`streamMessage`), **use non-streaming for tool-enabled chat** (`sendMessageWithTools`).

---

## Flow Overview

```
┌─────────────┐     POST /messages-with-tools      ┌─────────────┐
│   Frontend  │ ─────────────────────────────────► │   Backend   │
│   (e.g.    │     { message: "...", context }     │ ChatController
│   React)   │                                     │ sendMessageWithTools
└─────────────┘                                    └──────┬──────┘
       ▲                                                  │
       │                                                  ▼
       │                                          ChatService::
       │                                          sendMessageWithTools()
       │                                                  │
       │                          ┌──────────────────────┴──────────────────────┐
       │                          │                                             │
       │                    LLM returns text                             LLM returns
       │                    (no tools)                                     tool calls
       │                          │                                             │
       │                          ▼                                             ▼
       │                   message + action_requests: []              Create ActionRequests
       │                                                              message + action_requests: [...]
       │                          │                                             │
       │                          └──────────────────────┬──────────────────────┘
       │                                                  │
       │   JSON response:                                  │
       │   { data: { message, action_requests } }          │
       │◄──────────────────────────────────────────────────┘
       │
       │   If action_requests.length > 0:
       │   → Show confirmation UI
       │   → User clicks "Confirm" or "Reject"
       │
       │   POST /action-requests/{id}/confirm   (or /reject)
       │   → Backend runs ExecuteActionRequestJob
       │   → Optionally poll or refresh conversation
       │
       ▼
```

---

## Example 1: Frontend Call (JavaScript/TypeScript)

### 1. Send message with tools

```javascript
// User types: "Create a new blog post titled 'Hello World' with body 'First post.'"

const conversationId = 42; // from your app state

const response = await fetch(
  `/crud/insert/conversations/${conversationId}/messages-with-tools`,
  {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
    },
    body: JSON.stringify({
      message: "Create a new blog post titled 'Hello World' with body 'First post.'",
      context: {} // optional, e.g. { use_rag: true }
    }),
  }
);

const json = await response.json();
// Response shape (ResponseBuilder): { data: { ... }, meta: { ... } }
const { message, action_requests } = json.data;
```

### 2. Handle response: text only

When the LLM answers with normal text (no tool calls):

```javascript
// json.data looks like:
{
  "message": {
    "id": 101,
    "role": "assistant",
    "content": "Sure! I can help you with that. What topic would you like to write about?",
    "metadata": null,
    "created_at": "2026-01-30T12:00:00.000000Z"
  },
  "action_requests": []
}

// Your UI: append this message to the conversation and show it.
appendMessage(message);
```

No confirmation step; just show the message.

### 3. Handle response: LLM proposed tool calls

When the LLM decides to call a tool (e.g. `create_blog_post`):

```javascript
// json.data looks like:
{
  "message": {
    "id": 102,
    "role": "assistant",
    "content": "⚠️ Requires your confirmation: Create blog post",
    "metadata": {
      "tool_calls": [
        {
          "id": 1,
          "tool": "create_blog_post",
          "status": "pending_user_confirmation",
          "risk_level": "medium"
        }
      ]
    },
    "created_at": "2026-01-30T12:01:00.000000Z"
  },
  "action_requests": [
    {
      "id": 1,
      "tool_name": "create_blog_post",
      "tool_args": { "title": "Hello World", "body": "First post." },
      "risk_level": "medium",
      "status": "pending_user_confirmation",
      "result": null,
      "error": null,
      "created_at": "2026-01-30T12:01:00.000000Z"
    }
  ]
}
```

Your UI should:

1. Append the **message** to the conversation (so the user sees “Requires your confirmation: Create blog post”).
2. Show a **confirmation block** for each `action_request` (e.g. “Create blog post with title ‘Hello World’?” [Confirm] [Reject]).

### 4. User confirms an action

```javascript
// User clicked "Confirm" for action_request id 1

await fetch(`/crud/update/action-requests/1/confirm`, {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
  },
  body: JSON.stringify({}),
});

// Backend dispatches ExecuteActionRequestJob; tool runs (e.g. creates blog post).
// You can refresh the conversation or poll action-request detail to show "completed" / result.
```

### 5. User rejects an action

```javascript
await fetch(`/crud/update/action-requests/1/reject`, {
  method: 'POST',
  headers: { /* same as above */ },
  body: JSON.stringify({}),
});
```

---

## Example 2: cURL

### Send message with tools

```bash
curl -X POST "https://yourapp.com/crud/insert/conversations/42/messages-with-tools" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Cookie: ..." \
  -d '{
    "message": "Create a new blog post titled Hello World",
    "context": {}
  }'
```

### Confirm action (medium risk)

```bash
curl -X POST "https://yourapp.com/crud/update/action-requests/1/confirm" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Cookie: ..." \
  -d '{}'
```

### Approve action (high risk, admin)

```bash
curl -X POST "https://yourapp.com/crud/update/action-requests/2/approve" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Cookie: ..." \
  -d '{}'
```

---

## When to Use Stream vs Tools


| Use case                          | Endpoint                                    | Reason                                                                             |
| --------------------------------- | ------------------------------------------- | ---------------------------------------------------------------------------------- |
| Normal chat, no actions           | `POST .../messages` (stream)                | Better UX: text appears as it’s generated.                                         |
| Chat where AI can trigger actions | `POST .../messages-with-tools` (non-stream) | You need full response with `message` + `action_requests` to show confirmation UI. |
| FAQ / documentation (no tools)    | `POST .../messages` (insertMessage)         | RAG is implemented there; no tools needed.                                         |


Summary:

- **Stream** = plain chat, no tools.
- **Tools** = non-stream; response is one JSON with message and optional action requests; then confirm/reject via separate endpoints.

---

## Minimal React-Like Example

```jsx
// Pseudocode: one component that chooses stream vs tools

function ChatInput({ conversationId, useTools }) {
  const [input, setInput] = useState('');
  const [loading, setLoading] = useState(false);

  const sendMessage = async () => {
    setLoading(true);
    try {
      if (useTools) {
        // Non-streaming: we need the full JSON to handle action_requests
        const res = await fetch(
          `/crud/insert/conversations/${conversationId}/messages-with-tools`,
          {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', /* ... */ },
            body: JSON.stringify({ message: input, context: {} }),
          }
        );
        const { data } = await res.json();
        appendMessage(data.message);
        if (data.action_requests?.length > 0) {
          showConfirmationUI(data.action_requests);
        }
      } else {
        // Streaming: open EventSource or fetch with stream
        const res = await fetch(
          `/crud/stream/conversations/${conversationId}/messages`,
          {
            method: 'POST',
            body: JSON.stringify({ message: input, context: {} }),
          }
        );
        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let content = '';
        while (true) {
          const { done, value } = await reader.read();
          if (done) break;
          const chunk = decoder.decode(value, { stream: true });
          // Parse SSE and append chunks to UI
          content += parseSSEChunk(chunk);
          updateLastMessageContent(content);
        }
      }
    } finally {
      setLoading(false);
    }
    setInput('');
  };

  return (
    <div>
      <input value={input} onChange={e => setInput(e.target.value)} />
      <button onClick={sendMessage} disabled={loading}>Send</button>
    </div>
  );
}
```

---

## Summary

- **sendMessageWithTools** is used when the AI can propose **actions** (tools) that need user confirmation or admin approval.
- It is **non-streaming** because the server must return a single JSON response with both the assistant message and the list of action requests.
- Frontend flow: send message → get `{ message, action_requests }` → show message and, if any, confirmation UI → call confirm/approve/reject endpoints.
- Use **streaming** for normal chat without tools; use **messages-with-tools** when you need tools and confirmation.

