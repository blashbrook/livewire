<?php

namespace Livewire\ComponentConcerns;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Livewire\ObjectPrybar;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Exceptions\MissingRulesException;

trait ValidatesInput
{
    protected $errorBag;

    public function getErrorBag()
    {
        return $this->errorBag ?? new MessageBag;
    }

    public function addError($name, $message)
    {
        return $this->getErrorBag()->add($name, $message);
    }

    public function setErrorBag($bag)
    {
        return $this->errorBag = $bag instanceof MessageBag
            ? $bag
            : new MessageBag($bag);
    }

    public function resetErrorBag($field = null)
    {
        $fields = (array) $field;

        if (empty($fields)) {
            return $this->errorBag = new MessageBag;
        }

        $this->setErrorBag(
            $this->errorBagExcept($fields)
        );
    }

    public function clearValidation($field = null)
    {
        $this->resetErrorBag($field);
    }

    public function resetValidation($field = null)
    {
        $this->resetErrorBag($field);
    }

    public function errorBagExcept($field)
    {
        $fields = (array) $field;

        return new MessageBag(
            collect($this->getErrorBag())
                ->reject(function ($messages, $messageKey) use ($fields) {
                    return collect($fields)->some(function ($field) use ($messageKey) {
                        return Str::is($field, $messageKey);
                    });
                })
                ->toArray()
        );
    }

    protected function getRules()
    {
        if (method_exists($this, 'rules')) return $this->rules();
        if (property_exists($this, 'rules')) return $this->rules;

        return [];
    }

    protected function getMessages()
    {
        if (method_exists($this, 'messages')) return $this->messages();
        if (property_exists($this, 'messages')) return $this->messages;

        return [];
    }

    public function rulesForModel($name)
    {
        if (empty($this->getRules())) return collect();

        return collect($this->getRules())
            ->filter(function ($value, $key) use ($name) {
                return $this->beforeFirstDot($key) === $name;
            });
    }

    public function hasRuleFor($dotNotatedProperty)
    {
        // Convert foo.0.bar.1 -> foo.*.bar.*
        $propertyWithStarsInsteadOfNumbers = (string) Str::of($dotNotatedProperty)
            // Replace all numeric indexes with an array wildcard: (.0., .10., .007.) => .*.
            // In order to match overlapping numerical indexes (foo.1.2.3.4.name),
            // We need to use a positive look-behind, that's technically all the magic here.
            // For better understanding, see: https://regexr.com/5d1n3
            ->replaceMatches('/(?<=(\.))\d+\./', '*.')
            // Replace all numeric indexes at the end of the name with an array wildcard
            // (Same as the previous regex, but ran only at the end of the string)
            // For better undestanding, see: https://regexr.com/5d1n6
            ->replaceMatches('/\.\d+$/', '.*');

        // If property has numeric indexes in it,
        if ($dotNotatedProperty !== $propertyWithStarsInsteadOfNumbers) {
            return collect($this->getRules())->keys()->contains($propertyWithStarsInsteadOfNumbers);
        }

        return collect($this->getRules())
            ->keys()
            ->map(function ($key) {
                return (string) Str::of($key)->before('.*');
            })->contains($dotNotatedProperty);
    }

    public function missingRuleFor($dotNotatedProperty)
    {
        return ! $this->hasRuleFor($dotNotatedProperty);
    }

    public function validate($rules = null, $messages = [], $attributes = [])
    {
        [$rules, $messages] = $this->providedOrGlobalRulesAndMessages($rules, $messages);

        $data = $this->prepareForValidation(
            $this->getDataForValidation($rules)
        );

        $validator = Validator::make($data, $rules, $messages, $attributes);

        $this->shortenModelAttributes($data, $rules, $validator);

        $validatedData = $validator->validate();

        $this->resetErrorBag();

        return $validatedData;
    }

    public function validateOnly($field, $rules = null, $messages = [], $attributes = [])
    {
        [$rules, $messages] = $this->providedOrGlobalRulesAndMessages($rules, $messages);

        // If the field is "items.0.foo", validation rules for "items.*.foo", "items.*", etc. are applied.
        $rulesForField = collect($rules)->filter(function ($rule, $fullFieldKey) use ($field) {
            return Str::is($fullFieldKey, $field);
        })->toArray();

        $ruleKeysForField = array_keys($rulesForField);

        $data = $this->prepareForValidation(
            $this->getDataForValidation($rules)
        );

        $validator = Validator::make($data, $rulesForField, $messages, $attributes);

        $this->shortenModelAttributes($data, $rulesForField, $validator);

        try {
            $result = $validator->validate();
        } catch (ValidationException $e) {
            $messages = $e->validator->getMessageBag();
            $target = new ObjectPrybar($e->validator);

            $target->setProperty(
                'messages',
                $messages->merge(
                    $this->errorBagExcept($ruleKeysForField)
                )
            );

            throw $e;
        }

        $this->resetErrorBag($ruleKeysForField);

        return $result;
    }

    protected function shortenModelAttributes($data, $rules, $validator)
    {
        // If a model ($foo) is a property, and the validation rule is
        // "foo.bar", then set the attribute to just "bar", so that
        // the validation message is shortened and more readable.
        foreach ($rules as $key => $value) {
            $propertyName = $this->beforeFirstDot($key);

            if ($data[$propertyName] instanceof Model) {
                if ($key === $validator->getDisplayableAttribute($key)) {
                    $validator->addCustomAttributes([$key => $this->afterFirstDot($key)]);
                }
            }
        }
    }

    protected function providedOrGlobalRulesAndMessages($rules, $messages)
    {
        $rules = is_null($rules) ? $this->getRules() : $rules;

        throw_if(empty($rules), new MissingRulesException($this::getName()));

        $messages = empty($messages) ? $this->getMessages() : $messages;

        return [$rules, $messages];
    }

    protected function getDataForValidation($rules)
    {
        $properties = $this->getPublicPropertiesDefinedBySubClass();

        collect($rules)->keys()
            ->each(function ($ruleKey) use ($properties) {
                $propertyName = $this->beforeFirstDot($ruleKey);

                throw_unless(array_key_exists($propertyName, $properties), new \Exception('No property found for validation: ['.$ruleKey.']'));
            });

        return collect($properties)->map(function ($value) {
            if ($value instanceof Collection && ! $value instanceof EloquentCollection) return $value->toArray();

            return $value;
        })->all();
    }

    protected function prepareForValidation($attributes)
    {
        return $attributes;
    }
}
