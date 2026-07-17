<?php

/*****************************************************************************
 * FanUpdate — OptionService
 *
 * Business logic for managing general site options: listing editable
 * options and updating a single option's value. Depends on
 * OptionRepositoryInterface (constructor injection), not a concrete
 * repository or the FanUpdate singleton, so it can be unit tested with a
 * fake/mock repository.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class OptionService
{
    public function __construct(
        private readonly OptionRepositoryInterface $options,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listEditableOptions(): array
    {
        return $this->options->listEditableOptions();
    }

    public function updateOption(string $key, string $value): ?int
    {
        return $this->options->updateOption($key, $value);
    }
}
