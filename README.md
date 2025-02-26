# Intelligent Intern Chat Unified Message Service Bundle

The `intelligent-intern/chat-unified_message-bundle` integrates chat messaging functionality with the Intelligent Intern Core Framework. This bundle allows you to handle unified messages (e.g., chat prompts) asynchronously via Messenger, process them with dynamic strategies (for example, using RAG or an agent network configured via Vault), and push responses to clients via Mercure.

## Installation

Install the bundle using Composer:

~~~bash
composer require intelligent-intern/chat-unified_message-bundle
~~~

## Configuration

### Vault Secrets

Ensure the following secret is set in vault:

~~~yaml
secret/data/data/chat:
  retryDelaySec: 15
~~~

This value controls the delay (in seconds) for retries when processing fails.

### Messenger

Make sure your Messenger component is configured to use a transport (e.g., RabbitMQ). For example, in your `config/packages/messenger.yaml`:

~~~yaml
framework:
    messenger:
        default_bus: messenger.bus.default
        routing:
            'App\\Entity\\UnifiedMessage': rabbitmq
        transports:
            rabbitmq:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    exchange:
                        name: messages
                        type: direct
                    queues:
                        unified_message:
                            binding_keys: [ unified_message ]
                retry_strategy:
                    max_retries: 5
                    delay: 1000
                    multiplier: 2
                    max_delay: 60000
~~~

### Services

Ensure your services configuration automatically registers the strategy. For example:

~~~yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: true

    IntelligentIntern\ChatUnifiedMessageBundle\Service\:
        resource: '../src/Service/*'
        public: true
        tags:
            - { name: 'unified_message.strategy' }
~~~

## Usage

When a message is sent from the frontend, it is handled by a controller which creates and persists a UnifiedMessage entity and dispatches it asynchronously via Messenger. The actual processing is done by a strategy (e.g., `ChatUnifiedMessageServiceStrategy`), which validates the payload, builds a chat context, and pushes responses via Mercure.

Example controller:

~~~php
<?php

namespace App\Controller;

use App\Entity\UnifiedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

class UnifiedMessagingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus
    ) {}

    #[Route('/channel/push', name: 'api_channel_push', methods: ['POST'])]
    public function pushMessage(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            if (!isset($data['module'], $data['payload'])) {
                return new JsonResponse(['error' => 'Missing required parameters'], 400);
            }
            $unifiedMessage = new UnifiedMessage();
            $unifiedMessage->setModule($data['module']);
            $unifiedMessage->setPayload($data['payload']);
            $this->entityManager->persist($unifiedMessage);
            $this->entityManager->flush();
            $this->messageBus->dispatch($unifiedMessage);
            return new JsonResponse(['status' => 'accepted'], 200);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Server error'], 500);
        }
    }
}
~~~

## Flow Diagram

Below is a Mermaid diagram representing the complete message flow:

~~~mermaid
sequenceDiagram
    participant FE as Frontend
    participant UC as UnifiedMessagingController
    participant MQ as Messenger (RabbitMQ)
    participant UMH as UnifiedMessageHandler
    participant CUMS as ChatUnifiedMessageService
    participant MERC as Mercure
    participant Client as Client (Subscribed via Mercure)
    
    FE->>UC: HTTP POST /channel/push
    UC->>UC: Validate request, create UnifiedMessage
    UC->>MQ: Dispatch UnifiedMessage via MessageBus
    MQ->>UMH: Consume UnifiedMessage
    UMH->>CUMS: ServiceFactory creates ChatUnifiedMessageService (tagged with unified_message.strategy)
    CUMS->>CUMS: Validate payload (JSON Schema)
    CUMS->>CUMS: Build chat context (system prompt, chat history, etc.)
    alt completionType = stream
        CUMS->>MERC: Publish message chunks via Mercure
    else completionType = batch/default
        CUMS->>MERC: Publish complete response via Mercure
    end
    MERC->>Client: Clients receive updates
~~~

## Extensibility

This bundle is designed to integrate with the Intelligent Intern Core Framework using dynamic service discovery.

- **Adding Additional Strategies:**  
  To add a new strategy, create a service class implementing the desired logic and tag it with `unified_message.strategy`.

- **JSON Schema Validation:**  
  The bundle uses the `justinrainbow/json-schema` package to validate incoming payloads, ensuring that only valid data is processed.

- **Retry Handling:**  
  When an error occurs, the strategy dispatches the message again with a delay (configured via Vault) to automatically retry processing.

## License

This bundle is open-sourced software licensed under the [MIT License](LICENSE).