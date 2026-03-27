<?php

class SendEmailJob
{
    public function __construct(private mixed $data = null) {}

    public function handle(): void
    {
        // Process the job
    }
}
