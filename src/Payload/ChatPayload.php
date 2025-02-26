<?php
namespace IntelligentIntern\ChatUnifiedMessageBundle\Payload;

class ChatPayload
{
    public static function factory(): self
    {
        return new self();
    }

    public function createJson(
        string $module,
        string $chatHistoryId,
               $message
    ): string {
        return json_encode([
            'module' => $module,
            'component' => 'chatWindow',
            'output' => [
                'type' => 'chatHistory',
                'chatHistoryId' => $chatHistoryId,
                'data' => $message,
            ],
        ]);
    }
}