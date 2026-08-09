<?php

namespace QCH\Core\Models;

use Spatie\Translatable\HasTranslations;

/**
 * Class BaseModel.
 */
trait HasTranslationsBase
{
    use HasTranslations;

    public function toArray()
    {
        $attributes = parent::toArray();
        foreach ($this->getTranslatableAttributes() as $field) {
            $attributes[$field] = $this->getTranslation($field, \App::getLocale());
        }

        return $attributes;
    }
}
