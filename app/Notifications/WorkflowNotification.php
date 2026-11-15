<?php

namespace App\Notifications;

/**
 * Generic workflow notification used by marketplace state changes. Each
 * producer supplies the registry event key, the user-facing copy, and an
 * optional safe internal action URL.
 */
class WorkflowNotification extends BearlyNotification
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        private readonly string $eventKey,
        private readonly string $title,
        private readonly string $body,
        private readonly ?string $actionUrl = null,
        private readonly string $type = 'info',
        private readonly array $meta = [],
    ) {}

    public function eventKey(): string
    {
        return $this->eventKey;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function actionUrl(): ?string
    {
        return $this->actionUrl;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }
}
