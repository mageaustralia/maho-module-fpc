<?php

/**
 * Mageaustralia_Fpc — Full Page Cache
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 */

declare(strict_types=1);

/**
 * AJAX Response builder — constructs JSON responses for AJAX endpoints.
 *
 * Used by DynamicController and any custom AJAX action that needs
 * a consistent response format.
 */
class Mageaustralia_Fpc_Model_Ajax_Response
{
    /** @var array<string, string> */
    private array $blocks = [];

    /** @var string[] */
    private array $messages = [];

    /** @var array<string, mixed> */
    private array $data = [];

    private bool $success = true;

    /**
     * Set success status.
     */
    public function setSuccess(bool $success): self
    {
        $this->success = $success;
        return $this;
    }

    /**
     * Add a rendered block.
     */
    public function addBlock(string $name, string $html): self
    {
        $this->blocks[$name] = $html;
        return $this;
    }

    /**
     * Set all blocks at once.
     *
     * @param array<string, string> $blocks
     */
    public function setBlocks(array $blocks): self
    {
        $this->blocks = $blocks;
        return $this;
    }

    /**
     * Add a message.
     */
    public function addMessage(string $message): self
    {
        $this->messages[] = $message;
        return $this;
    }

    /**
     * Set messages from an array.
     *
     * @param string[] $messages
     */
    public function setMessages(array $messages): self
    {
        $this->messages = $messages;
        return $this;
    }

    /**
     * Set arbitrary extra data.
     */
    public function setData(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * Build the final JSON response array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'success'  => $this->success,
            'messages' => $this->messages,
            'blocks'   => $this->blocks,
        ];

        return array_merge($result, $this->data);
    }

    /**
     * Encode as JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }
}
