# API Reference

## Subject Chat Endpoints

All endpoints are accessed via `/request/{sectionId}/therapy-chat/{action}`.

### Send Message

**POST** `/request/{sectionId}/therapy-chat/send`

Send a message from the subject.

**Request Body:**
```json
{
    "conversation_id": 123,
    "content": "Hello, I need some help..."
}
```

**Response (Success):**
```json
{
    "success": true,
    "user_message_id": 456,
    "conversation_id": 123,
    "ai_message": {
        "id": 457,
        "role": "assistant",
        "content": "I understand. How can I help you today?",
        "sender_type": "ai",
        "label": "AI Assistant",
        "timestamp": "2026-01-16T14:30:00+00:00"
    }
}
```

**Response (Blocked - Danger Detection):**
```json
{
    "blocked": true,
    "message": "I noticed some concerning content...",
    "detected_keywords": ["keyword1", "keyword2"]
}
```

### Get Messages

**GET** `/request/{sectionId}/therapy-chat/messages?after={lastId}&conversation_id={id}`

Get messages, optionally filtering to only new messages.

**Query Parameters:**
- `after` (optional) - Only return messages with ID > this value
- `conversation_id` (optional) - Conversation ID (uses current if omitted)

**Response:**
```json
{
    "messages": [
        {
            "id": 458,
            "role": "user",
            "content": "Thank you!",
            "sender_type": "subject",
            "sender_id": 10,
            "sender_name": "John Doe",
            "label": "You",
            "timestamp": "2026-01-16T14:31:00+00:00",
            "tags": []
        }
    ],
    "conversation_id": 123
}
```

### Tag Therapist

**POST** `/request/{sectionId}/therapy-chat/tag`

Tag the assigned therapist with a reason.

**Request Body:**
```json
{
    "conversation_id": 123,
    "reason": "overwhelmed",
    "urgency": "urgent"
}
```

**Response:**
```json
{
    "success": true,
    "tag_id": 45,
    "alert_created": true
}
```

## Therapist Dashboard Endpoints

All endpoints are accessed via `/request/{sectionId}/therapist-dashboard/{action}`.

### Send Therapist Message

**POST** `/request/{sectionId}/therapist-dashboard/send`

Send a message from the therapist to the subject.

**Request Body:**
```json
{
    "conversation_id": 123,
    "content": "I see you're having a difficult time..."
}
```

**Response:**
```json
{
    "success": true,
    "message_id": 459
}
```

### Toggle AI Responses

**POST** `/request/{sectionId}/therapist-dashboard/toggle-ai`

Enable or disable AI responses for a conversation.

**Request Body:**
```json
{
    "conversation_id": 123,
    "enabled": false
}
```

**Response:**
```json
{
    "success": true
}
```

### Set Risk Level

**POST** `/request/{sectionId}/therapist-dashboard/set-risk`

Update the risk level of a conversation.

**Request Body:**
```json
{
    "conversation_id": 123,
    "risk_level": "high"
}
```

**Response:**
```json
{
    "success": true
}
```

### Add Note

**POST** `/request/{sectionId}/therapist-dashboard/add-note`

Add a private therapist note to a conversation.

**Request Body:**
```json
{
    "conversation_id": 123,
    "content": "Patient showing improvement in anxiety management..."
}
```

**Response:**
```json
{
    "success": true,
    "note_id": 67
}
```

### Acknowledge Tag

**POST** `/request/{sectionId}/therapist-dashboard/acknowledge-tag`

Mark a tag as acknowledged.

**Request Body:**
```json
{
    "tag_id": 45
}
```

**Response:**
```json
{
    "success": true
}
```

### Mark Alert Read

**POST** `/request/{sectionId}/therapist-dashboard/mark-alert-read`

Mark an alert as read.

**Request Body:**
```json
{
    "alert_id": 89
}
```

**Response:**
```json
{
    "success": true
}
```

### Get Messages (Polling)

**GET** `/request/{sectionId}/therapist-dashboard/messages?conversation_id={id}&after={lastId}`

Get messages for a conversation.

**Response:**
```json
{
    "success": true,
    "messages": [...]
}
```

## Error Responses

All endpoints return errors in this format:

```json
{
    "error": "Error message describing what went wrong"
}
```

Common error codes:
- `"Access denied"` - User doesn't have permission
- `"Conversation not found"` - Invalid conversation ID
- `"Conversation ID is required"` - Missing required parameter
- `"Tagging is disabled"` - Tagging feature is turned off

## Data Types

### Message Object
```typescript
interface Message {
    id: number;
    role: 'user' | 'assistant' | 'system';
    content: string;
    sender_type: 'subject' | 'therapist' | 'ai' | 'system';
    sender_id: number | null;
    sender_name: string | null;
    label: string;
    timestamp: string; // ISO 8601
    tags: Tag[];
    attachments: Attachment[] | null;
}
```

### Tag Object
```typescript
interface Tag {
    id: number;
    id_users: number;
    tag_reason: string | null;
    urgency: 'normal' | 'urgent' | 'emergency';
    acknowledged: boolean;
    acknowledged_at: string | null;
    created_at: string;
    tagged_name: string;
}
```

### Risk Levels
- `low` - Normal conversation
- `medium` - Requires monitoring
- `high` - Needs attention
- `critical` - Immediate intervention required

### Urgency Levels
- `normal` - Standard tag
- `urgent` - Needs prompt response
- `emergency` - Immediate attention required
