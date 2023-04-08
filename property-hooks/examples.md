# Usage examples

This section includes a collection of examples the authors feel are representative of how hooks can and will be used.  It is non-normative, but gives a sense of how hooks can be used to improve a code base.

## Derived properties

This example demonstrates a virtual property that is calculated on the fly off of other values.

```php
class User
{
    public function __construct(private string $first, private string $last) {}

    public string $fullName => $this->first . " " . $this->last;
}
```

## Lazy/cached properties

Sometimes, a derived property may be expensive to compute.  The example above would recompute it every time.  However, the `??=` operator may be used to easily cache the value.

```php
class User
{
    private string $full;

    public function __construct(private string $first, private string $last) {}
    
    public string $fullName => $this->full ??= $this->first . " " . $this->last;
}
```

This does introduce a question of when to invalidate the cache.  If `$first` changes, `$full` will be out of date.  This is only a concern in some classes, but if it is then it may be addressed with the `afterSet` hook:

```php
class User
{
    private string $full;

    public function __construct(
        public string $first { afterSet => unset($this->full); }, 
        public string $last { afterSet => unset($this->full); },
    ) {}
    
    public string $fullName => $this->full ??= $this->first . " " . $this->last;
}
```

Now, `$fullName` will be cached but the cache reset any time `$first` or `$last` is updated.

If multiple virtual properties are to be cached, they can be collapsed into a single cache array like so:

```php
class User
{
    private array $cache = [];

    public function __construct(
        public string $first { afterSet => unset($this->cache['fullName']); }, 
        public string $last { afterSet => unset($this->cache['fullName']); },
    ) {}
    
    public string $fullName => $this->cache[__PROPERTY__] ??= $this->first . " " . $this->last;
}
```

All of these options are entirely transparent to the caller, making them easy to add after-the-fact.

## Type normalization

A `beforeSet` method takes the same variable type as the property is declared for.  However, there is no requirement that it cannot narrow the type.  For example:

```php
class ListOfStuff
{
    public iterable $items {
        beforeSet => is_array($value) ? new ArrayObject($value) : $value;
    }
}
```

This example will accept either an array or `Traversable`, but will ensure that the value that gets written is always a `Traversable` object.  That may be helpful for later internal logic.

Another example would be auto-boxing a value:

```php
use Symfony\Component\String\UnicodeString;

class Person
{
    public string|UnicodeString $name {
        beforeSet => $value instanceof UnicodeString ? $value : new UnicodeString($value);
    }
}
```

This example ensures that the `$name` property is always a `UnicodeString` instance, but allows users to write PHP strings to it.  Those will get automatically up-converted to `UnicodeStrings`, which then ensures future code only has one type to have to worry about.

## Validation

As mentioned, one of the main uses of `beforeSet` is validation.

```php
class Request
{
    public function __construct(
        public string $method = 'GET' { beforeSet => $this->normalizeMethod($value); },
        public string|Url $url { beforeSet => $url instanceof Url ? $url : new Url($url); },
        public array $body,
    ) {}

    private function normalizeMethod(string $method): string
    {
        $method = strtoupper($method);
        if (in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'HEAD']) {
            return $method;
        }
        throw new \InvalidArgumentException("$method is not a supported HTTP operation.");
    }
}
```

This example combines with the previous.  It allows only select HTTP methods through, and forces upcasting the URL to a `URL` object.  (Presumably the `URL` constructor contains logic to validate and reject invalid URL formats.)

## ORM change tracking

Note that this example is glossing over internal details of the ORM's loading process, as those often involve wonky reflection anyway.  That's not what is being discussed here.

Consider a domain object defined like this:

```php
class Product
{
    public readonly string $sku;

    public string $name;
    public Color $color;
    public float $price;
}
```

That is trivial to define, and to read.  However, it leaves open the potential to use hooks rather than needing to write this far longer version "just in case":

```php
class Product
{
    public readonly string $sku;

    private string $name;
    private Color $color;
    private float $price;
    
    // None of this is necessary.
    
    public function getName(): string
    {
        return $this->name;
    }
    
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getColor(): Color
    {
        return $this->color;
    }
    
    public function setColor(Color $color): void
    {
        $this->color = $color;
    }
    
    public function getPrice(): float
    {
        return $this->float;
    }
    
    public function setPrice(float $price): void
    {
        $this->price = $price;
    }
}
```

That means change tracking can be added to the object using hooks like this, without any change in the public-facing API:

```php
class Product
{
    private array $modified = [];

    public bool $hasChanges => !count($this->modified);
    
    public readonly string $sku;
    
    public string $name {
        afterSet => $this->modified[__PROPERTY__] = $value;
    }
    public Color $color {
        afterSet => $this->modified[__PROPERTY__] = $value;
    }
    public float $price {
        afterSet => $this->modified[__PROPERTY__] = $value;
    }
    
    public function modifications(): array
    {
        return $this->modified;
    {
}


class ProductRepo
{
    public function save(Product $p)
    {
        // Here we're checking a boolean property that is computed on the fly.
        if ($p->hasChanges) {
            // We can get the list here, but not change it.
            $fields = $p->modifications();
            // Do something with an SQL builder to write just the changed properties,
            // or build an EventSource event with just the changes, or whatever.
        }
    }
}

$repo = new ProductRepo();

$p = $repo->load($sku);

// This is type checked.
$p->price = 99.99;

// This is also type checked.
$p->color = new Color('#ff3378');

$repo->save($p);
```

## Definitional interfaces

A common pattern for many PHP libraries is to have an interface that defines methods that return simple strings.  Alternatively, some define magic public properties, which have all the corresponding problems of public properties.

The use of properties on interfaces obviates both issues.

For example, this is a real interface out of a [library maintained by one of the RFC authors](http://github.com/Crell/AttributeUtils|Crell/AttributeUtils), and a typical class that implements it:

```php
interface ParseProperties
{
    public function setProperties(array $properties): void;

    public function includePropertiesByDefault(): bool;

    public function propertyAttribute(): string;
}

#[\Attribute(\Attribute::TARGET_CLASS)]
class ClassWithProperties implements ParseProperties
{
    public array $properties = [];

    public function __construct(
        public readonly int $a = 0,
        public readonly int $b = 0,
    ) {}

    public function setProperties(array $properties): void
    {
        $this->properties = $properties;
    }

    public function includePropertiesByDefault(): bool
    {
        return true;
    }

    public function propertyAttribute(): string
    {
        return BasicProperty::class;
    }
}
```

The `includePropertiesByDefault()` and `propertyAttribute()` methods will, 99% of the time, be static strings.  But making them just a property today would both make them publicly editable and make the other 1% case, where one of the values should be dynamic, impossible or extremely difficult.

This RFC would allow that to be simplified to:

```php
interface ParseProperties
{
    public function setProperties(array $properties): void;

    public bool $includePropertiesByDefault { get; }

    public string $propertyAttribute { get; }
}

#[\Attribute(\Attribute::TARGET_CLASS)]
class ClassWithProperties implements ParseProperties
{
public array $properties = [];

    // This would be technically publicly editable
    // unless asymmetric visibility is added.
    public bool $includePropertiesByDefault = true;
    
    // This simulates public-read-only while still
    // fulfilling the interface.
    public bool $propertyAttribute => BasicProperty::class;

    public function __construct(
        public readonly int $a = 0,
        public readonly int $b = 0,
    ) {}

    public function setProperties(array $properties): void
    {
        $this->properties = $properties;
    }
}
```

And should it become necessary to make either of them dynamic, that can be trivially done without any API change:

```php
#[\Attribute(\Attribute::TARGET_CLASS)]
class ClassWithProperties implements ParseProperties
{
    public array $properties = [];

    public bool $includePropertiesByDefault => $this->include;
    
    public bool $propertyAttribute => $this->propertiesAs;

    public function __construct(
        public readonly int $a = 0,
        public readonly int $b = 0,
        private readonly $include = true;
        private readonly $propertiesAs = BasicProperty::class,
    ) {}

    public function setProperties(array $properties): void
    {
        $this->properties = $properties;
    }
}
```
