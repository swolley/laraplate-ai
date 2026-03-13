<?php

declare(strict_types=1);

namespace Modules\Core\Helpers;

if (class_exists(ResponseBuilder::class, false)) {
    return;
}

class ResponseBuilder
{
    private mixed $data = null;

    private ?string $error = null;

    private int $status = 200;

    public function setData(mixed $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function setError(string $error): self
    {
        $this->error = $error;

        return $this;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function setTotalRecords(mixed $v): self
    {
        return $this;
    }

    public function setCurrentRecords(mixed $v): self
    {
        return $this;
    }

    public function setCurrentPage(mixed $v): self
    {
        return $this;
    }

    public function setTotalPages(mixed $v): self
    {
        return $this;
    }

    public function setPagination(mixed $v): self
    {
        return $this;
    }

    public function json(): \Illuminate\Http\JsonResponse
    {
        $payload = $this->error !== null
            ? ['error' => $this->error, 'data' => $this->data]
            : ['data' => $this->data];

        return new \Illuminate\Http\JsonResponse($payload, $this->status);
    }
}
