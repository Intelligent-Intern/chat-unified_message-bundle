<?php
namespace IntelligentIntern\ChatUnifiedMessageBundle\Service;

use App\Contract\UnifiedMessageInterface;
use App\Entity\ChatMessageEntry;
use App\Repository\InternRepository;
use App\Repository\ChannelRepository;
use App\Factory\ChatMemoryServiceFactory;
use App\Factory\LogServiceFactory;
use App\Contract\LogServiceInterface;
use App\Service\VaultService;
use Exception;
use IntelligentIntern\ChatUnifiedMessageServiceBundle\Payload\ChatPayload;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use JsonSchema\Validator;

class ChatUnifiedMessageService
{
    private LogServiceInterface $logger;
    private int $retryDelaySec;

    /**
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     */
    public function __construct(
        private readonly HubInterface $hub,
        private readonly LogServiceFactory $logServiceFactory,
        private readonly ChatMemoryServiceFactory $chatMemoryServiceFactory,
        private readonly InternRepository $internRepository,
        private readonly ChannelRepository $channelRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly VaultService $vaultService,
        private readonly TranslatorInterface $translator
    ) {
        $this->logger = $this->logServiceFactory->create();
        $chatConfig = $this->vaultService->fetchSecret('secret/data/data/chat');
        $this->retryDelaySec = $chatConfig['retryDelaySec'] ?? $this->logger->critical('retryDelaySec not found in Vault.');
    }

    /**
     * @throws Exception
     */
    public function handleMessage(UnifiedMessageInterface $unifiedMessage): void
    {
        $chatPrompt = json_decode(
            json_encode($unifiedMessage->getPayload()),
            true
        );

        $schemaJson = <<<'JSON'
{
    "$schema": "http://json-schema.org/draft-07/schema#",
    "type": "object",
    "properties": {
        "internId": { "type": "integer" },
        "channelId": { "type": "integer" },
        "chatMemoryType": { "type": "string" },
        "prompt": { "type": "string" },
        "completionType": { "type": "string", "enum": ["stream", "batch", "default"] },
        "chatHistoryId": { "type": "string" },
        "action": { "type": "string" },
        "messageId": { "type": "string" }
    },
    "required": ["internId", "channelId", "chatMemoryType", "prompt", "completionType", "chatHistoryId"]
}
JSON;
        $schema = json_decode($schemaJson);
        $validator = new Validator();
        $validator->validate($chatPrompt, $schema);
        if (!$validator->isValid()) {
            foreach ($validator->getErrors() as $error) {
                $this->logger->error("JSON Schema Error: " . $error['message'], $error);
            }
            throw new Exception("Unified message payload is invalid.");
        }

        try {
            $intern = $this->internRepository
                ->find($chatPrompt['internId']);
            $channelEntity = $this->channelRepository
                ->find($chatPrompt['channelId']);
            $chatMemory = $this->chatMemoryServiceFactory
                ->create($chatPrompt['chatMemoryType'])
                ->addMessageEntry(
                    ChatMessageEntry::factory()
                        ->setRole('system')
                        ->setMessage($intern->getSystemPrompt())
                )
                ->addMessageEntry(
                    ChatMessageEntry::factory()
                        ->setRole('user')
                        ->setMessage($chatPrompt['prompt'])
                );
            if (isset($chatPrompt['action'])) {
                $chatMemory->addAction($chatPrompt['action']);
            }
            if (isset($chatPrompt['messageId'])) {
                $chatMemory->addMessageId($chatPrompt['messageId']);
            }
            $chatMemory = $chatMemory->getChatHistory(
                $intern->getEngine(),
                $intern->getModel(),
                $intern->getOptions()
            );
            switch ($chatPrompt['completionType']) {
                case 'stream':
                    $stream = $chatMemory->getCompletionStream();
                    while ($stream->isValid()) {
                        $chunk = $stream->current();
                        $payloadJson = ChatPayload::factory()
                            ->createJson(
                                'chat',
                                $chatPrompt['chatHistoryId'],
                                $chunk
                            );
                        $update = new Update(
                            $channelEntity->getName(),
                            $payloadJson,
                            false
                        );
                        $this->hub->publish(
                            $update,
                            ['Authorization' => 'Bearer ' . $channelEntity->getJwt()]
                        );
                        $stream->next();
                    }
                    return;
                case 'batch':
                    $msg = $this->translator->trans(
                            'batch_sent - batchId:'
                        ) . $chatMemory->getBatchId();
                    $payloadJson = ChatPayload::factory()
                        ->createJson(
                            'chat',
                            $chatPrompt['chatHistoryId'],
                            $msg
                        );
                    break;
                case 'default':
                default:
                    $payloadJson = ChatPayload::factory()
                        ->createJson(
                            'chat',
                            $chatPrompt['chatHistoryId'],
                            $chatMemory
                        );
                    break;
            }
            $update = new Update(
                $channelEntity->getName(),
                $payloadJson,
                false
            );
            $this->hub->publish(
                $update,
                ['Authorization' => 'Bearer ' . $channelEntity->getJwt()]
            );

            return;
        } catch (Exception
        | ClientExceptionInterface
        | DecodingExceptionInterface
        | RedirectionExceptionInterface
        | ServerExceptionInterface
        | TransportExceptionInterface $e) {
            $this->logger->error(
                'Error in ChatUnifiedMessageServiceStrategy',
                ['exception' => $e]
            );
            $delayStamp = new DelayStamp($this->retryDelaySec * 1000);
            try {
                $this->messageBus->dispatch($unifiedMessage, [$delayStamp]);
            } catch (ExceptionInterface $e) {
                $this->logger->error(
                    'Error dispatching unifiedMessage for retry',
                    ['exception' => $e]
                );
            }
        }
    }
}