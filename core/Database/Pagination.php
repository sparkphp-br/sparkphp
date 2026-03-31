<?php

declare(strict_types=1);

class SparkPaginator implements \JsonSerializable
{
    public function __construct(
        public array $data,
        public int $total,
        public int $per_page,
        public int $current_page,
        public int $last_page,
        public int $from,
        public int $to,
        public array $links = [],
        public array $meta = [],
    ) {}

    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'total' => $this->total,
            'per_page' => $this->per_page,
            'current_page' => $this->current_page,
            'last_page' => $this->last_page,
            'from' => $this->from,
            'to' => $this->to,
            'links' => $this->links,
            'meta' => $this->meta,
        ];
    }

    public function toApi(array $options = []): array
    {
        $jsonApi = ($options['json_api'] ?? false) === true;

        $data = array_map(function (mixed $item) use ($jsonApi, $options): mixed {
            if (!$item instanceof Model) {
                return $item;
            }

            return $jsonApi
                ? $item->toJsonApiResource($options)
                : $item->toApi($options);
        }, $this->data);

        $document = [
            'data' => $data,
            'links' => $this->links,
            'meta' => array_merge($this->meta, $options['meta'] ?? []),
        ];

        if (!empty($options['links'])) {
            $document['links'] = array_merge($this->links, $options['links']);
        }

        return $document;
    }

    public function jsonSerialize(): mixed
    {
        return $this->toApi();
    }
}
