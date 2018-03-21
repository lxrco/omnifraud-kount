<?php

namespace Omnifraud\Kount;

use Omnifraud\Contracts\MessageInterface;
use Omnifraud\Contracts\ResponseInterface;
use Omnifraud\Response\BaseMessage;
use Kount_Ris_Response;

class KountResponse implements ResponseInterface
{
    /** @var \Kount_Ris_Response */
    protected $risResponse;

    /**
     * KountResponse constructor.
     *
     * @param $risResponse
     */
    public function __construct(Kount_Ris_Response $risResponse)
    {
        $this->risResponse = $risResponse;
    }

    public function getMessages(): array
    {
        $messages = [];
        foreach ($this->risResponse->getErrors() as $error) {
            $messages[] = new BaseMessage(MessageInterface::TYPE_ERROR, 'ERR', $error);
        }
        foreach ($this->risResponse->getWarnings() as $warning) {
            $messages[] = new BaseMessage(MessageInterface::TYPE_WARNING, 'WAR', $warning);
        }
        return $messages;
    }

    public function getPercentScore(): float
    {
        return 100 - $this->risResponse->getScore();
    }

    public function isAsync(): bool
    {
        return false;
    }

    public function isGuaranteed(): bool
    {
        return false;
    }

    public function getRawResponse(): string
    {
        return json_encode($this->risResponse->getResponseAsDict());
    }

    public function getRequestUid(): string
    {
        return (string)$this->risResponse->getTransactionId();
    }
}
