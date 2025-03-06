<?php

namespace IntelligentIntern\ChatUnifiedMessageBundle\Service;

use App\Contract\UnifiedMessageServiceInterface;
use App\Entity\ChatMessageEntry;
use App\Entity\UnifiedMessage;
use App\Factory\ChatCompletionServiceFactory;
use App\Repository\InternRepository;
use App\Repository\ChannelRepository;
use App\Factory\ChatMemoryServiceFactory;
use App\Factory\LogServiceFactory;
use App\Contract\LogServiceInterface;
use App\Service\VaultService;
use Exception;
use IntelligentIntern\ChatUnifiedMessageBundle\Payload\ChatPayload;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use JsonSchema\Validator;

class ChatUnifiedMessageService implements UnifiedMessageServiceInterface
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
        private readonly ChatCompletionServiceFactory $chatCompletionServiceFactory,
        private readonly InternRepository $internRepository,
        private readonly ChannelRepository $channelRepository,
        private readonly VaultService $vaultService,
        private readonly TranslatorInterface $translator
    ) {
        $this->logger = $this->logServiceFactory->create();
        $chatConfig = $this->vaultService->fetchSecret('secret/data/data/config');
        $this->retryDelaySec = $chatConfig['retryDelaySec'] ?? 15;
        $this->logger->debug('ChatUnifiedMessageService constructed', [
            'retryDelaySec' => $this->retryDelaySec,
        ]);
    }

    public function supports(string $module): bool
    {
        return $module === 'chat';
    }

    /**
     * @throws Exception
     */
    public function handleMessage(UnifiedMessage $message): void
    {
        $chatPrompt = $message->getPayload();

        $schemaJson = <<<'JSON'
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "type": "object",
  "properties": {
    "internId": { "type": "integer" },
    "channel": { "type": "string" },
    "chatMemoryType": { "type": "string" },
    "prompt": { "type": "string" },
    "completionType": { "type": "string", "enum": ["stream", "batch", "default"] },
    "chatHistoryId": { "type": "string" },
    "action": { "type": "string" },
    "messageId": { "type": "string" }
  },
  "required": ["internId", "channel", "chatMemoryType", "prompt", "completionType"]
}
JSON;
        $schema = json_decode($schemaJson);
        $validator = new Validator();
        $json_decode = json_decode(json_encode($chatPrompt));

        $validator->validate($json_decode, $schema);
        if (!$validator->isValid()) {
            foreach ($validator->getErrors() as $error) {
                $this->logger->error('JSON Schema Error: ' . $error['message'], $error);
            }
            throw new Exception("Unified message payload is invalid.");
        }

        try {
            $intern = $this->internRepository->find($chatPrompt['internId']);
            if (!$intern) {
                $this->logger->error('Intern not found - internId: ' . $chatPrompt['internId']);
                throw new Exception('Intern not found');
            }
            $channelEntity = $this->channelRepository->findOneBy(['name' => $chatPrompt['channel']]);
            if (!$channelEntity) {
                $this->logger->error('Channel not found - channel name: ' . $chatPrompt['channel']);
                throw new Exception('Channel not found');
            }
            $this->logger->debug('Building ChatMemory - type: ' . $chatPrompt['chatMemoryType']);

            $systemPrompt = ChatMessageEntry::factory()
                ->setRole('system')
                ->setMessage($intern->getSystemPrompt());

            $userPrompt = ChatMessageEntry::factory()
                ->setRole('user')
                ->setMessage($chatPrompt['prompt']);

            $chatMemory = $this->chatMemoryServiceFactory
                ->create($chatPrompt['chatMemoryType'])
                ->addMessageEntry($systemPrompt)
                ->addMessageEntry($userPrompt)->getChatHistory();

            $chatResponse = $this->chatCompletionServiceFactory
                ->create('openai')
                ->generateResponse('gpt4o', $chatMemory);

            $payloadJson = ChatPayload::factory()
                ->createJson(
                    'chat',
                    $chatPrompt['chatHistoryId'] ?? '',
                    $chatResponse->getContent()
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
            return;

            $this->logger->debug('ChatMemory :' . json_encode($chatMemory));

            if (isset($chatPrompt['action'])) {
                $this->logger->debug('Adding action: ' . $chatPrompt['action']);
                $chatMemory->addAction($chatPrompt['action']);
            }
            if (isset($chatPrompt['messageId'])) {
                $this->logger->debug('Adding messageId: ' . $chatPrompt['messageId']);
                $chatMemory->addMessageId($chatPrompt['messageId']);
            }
            $chatMemory = $chatMemory->getChatHistory(
                $intern->getEngine(),
                $intern->getModel(),
                $intern->getOptions()
            );

            $payloadJson = '';
            switch ($chatPrompt['completionType']) {
                case 'stream':
                    $this->logger->debug('Processing stream completion');
                    $stream = $chatMemory->getCompletionStream();
                    while ($stream->isValid()) {
                        $chunk = $stream->current();
                        $payloadJson = ChatPayload::factory()
                            ->createJson(
                                'chat',
                                $chatPrompt['chatHistoryId'] ?? '',
                                $chunk
                            );
                        $this->logger->debug('Publishing stream chunk', ['chunk' => $chunk]);
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
                    $this->logger->debug('Processing batch completion');
                    $msg = $this->translator->trans('batch_sent - batchId:') . $chatMemory->getBatchId();
                    $payloadJson = ChatPayload::factory()
                        ->createJson(
                            'chat',
                            $chatPrompt['chatHistoryId'] ?? '',
                            $msg
                        );
                    break;
                case 'default':
                default:
                    $this->logger->debug('Processing default completion');
                    $payloadJson = ChatPayload::factory()
                        ->createJson(
                            'chat',
                            $chatPrompt['chatHistoryId'] ?? '',
                            $chatMemory
                        );
                    break;
            }
            $this->logger->debug('Publishing final update', ['payloadJson' => $payloadJson]);
            $update = new Update(
                $channelEntity->getName(),
                $payloadJson,
                false
            );
            $this->hub->publish(
                $update,
                ['Authorization' => 'Bearer ' . $channelEntity->getJwt()]
            );
            $this->logger->debug('Message processed successfully');
            return;
        } catch (\Exception |
        ClientExceptionInterface |
        DecodingExceptionInterface |
        RedirectionExceptionInterface |
        ServerExceptionInterface |
        TransportExceptionInterface $e) {
            $this->logger->error('Error in ChatUnifiedMessageService' . $e->getMessage());
        }
    }
}
