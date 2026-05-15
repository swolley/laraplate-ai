<?php

declare(strict_types=1);

namespace Modules\AI\Enums;

enum AITables: string
{
    case Conversations = 'ai_conversations';
    case Messages = 'ai_messages';
    case ConversationSummaries = 'ai_conversation_summaries';
    case ActionRequests = 'ai_action_requests';
    case ContextualSuggestions = 'ai_contextual_suggestions';
}
