<?php

/*****************************************************************************
 * FanUpdate — TemplateService
 *
 * Business logic for managing templates: listing template-flavoured
 * options and updating a single option's value. Depends on
 * TemplateRepositoryInterface (constructor injection), not a concrete
 * repository or the FanUpdate singleton, so it can be unit tested with a
 * fake/mock repository.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class TemplateService
{
    public function __construct(
        private readonly TemplateRepositoryInterface $templates,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTemplateOptions(): array
    {
        return $this->templates->listTemplateOptions();
    }

    public function updateOption(string $key, string $value): ?int
    {
        return $this->templates->updateOption($key, $value);
    }
}
