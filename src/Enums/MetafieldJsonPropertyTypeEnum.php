<?php

declare(strict_types=1);

namespace Nvl\Metafields\Enums;

enum MetafieldJsonPropertyTypeEnum: string
{
    case String = 'string';
    case Text = 'text';
    case RichText = 'rich_text';
    case Integer = 'integer';
    case Float = 'float';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Url = 'url';
    case Color = 'color';

    public function toMetafieldType(): MetafieldTypeEnum
    {
        return match ($this) {
            self::String => MetafieldTypeEnum::String,
            self::Text => MetafieldTypeEnum::Text,
            self::RichText => MetafieldTypeEnum::RichText,
            self::Integer => MetafieldTypeEnum::Integer,
            self::Float => MetafieldTypeEnum::Float,
            self::Boolean => MetafieldTypeEnum::Boolean,
            self::Date => MetafieldTypeEnum::Date,
            self::DateTime => MetafieldTypeEnum::DateTime,
            self::Url => MetafieldTypeEnum::Url,
            self::Color => MetafieldTypeEnum::Color,
        };
    }

    /**
     * @return list<string>
     */
    public function getValidationRules(): array
    {
        return array_values($this->toMetafieldType()->getValidationRules());
    }
}
