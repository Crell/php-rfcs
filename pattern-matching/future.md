# Future scope extensions for pattern matching

==== Array-application pattern ====

One possible extension of patterns is the built-in ability to apply a pattern across an array.  While that could be done straightforwardly with a <php>foreach</php> loop over an array, it may be more performant if the entire logic could be pushed into engine-space.  One possible approach would look like this:

```php
$ints = [1, 2, 3, 4];
$someFloats = [1, 2, 3.14, 4];

$ints is array<int>; //True.  
$someFloats is array<int>; // False
$someFloats is array<int|float>; // True

// Equivalent to:
$result = true;
foreach ($ints as $v) {
  if (!is_int($v)) {
    $result = false;
    break;
  }
}
```

It is not yet clear if it would indeed be more performant than the user-space alternative, or how common that usage would be.  For that reason it has been left out of the RFC for now, but we mention it as a possible future extension.

==== as keyword ====

In some cases, the desired result is not a boolean but an error condition.  One possible way to address that would be with a second keyword, <php>as</php>, which behaves the same as <php>is</php> but returns the matched value or throws an Error rather than returning false.

```php
// This either evaluates to true and assigns $username and $password to the matching properties of Foo, OR it evaluates to false.
$foo is Foo { $username, $password };

// This either evaluates to $foo and assigns $username and $password to the matching properties of Foo, OR it throws an Error.
$value = $foo as Foo { $username, $password };
</PHP>
```

This pattern could potentially be combined with the "weak mode flag" (see below) to offer object validation with embedded coercion.

==== "weak mode" flag ====

By default, pattern matching uses strict comparisons.  However, there are use cases where a weak comparison is more appropriate.  Setting a pattern or sub-pattern to weak-mode would permit standard PHP type coercion to determine if a value matches.

For example:

```php
$s = "5";

// Default, strict mode

$s is int; // False

// Opt-in weak mode

$s is ~int // True
```

This would be particularly useful in combination with an array application pattern, to verify that, for instance, all elements in an array are numeric.

```php
$a = [2, 4, "6", 8];

$a is array<int>; // False

$a is array<~int>; // True
```

It is possible that we could extend the ''as'' keyword here as well to save the coercion.  That is, if the value is weakly compatible, the ''as'' keyword would convert it safely (or throw if it cannot be).  That would allow validation across an object or array in a single operation.

For example:

```php
$a = [2, 4, "6", 8];

$intifiedA = $a as array<~int>;

// $initifiedA is now [2, 4, 6, 8]

$b = [2, 4, 'six', 8];

$intifiedB = $b as array<~int>; // Throws, because 'six' is not coerce-able to an integer.
```

We have not yet investigated how feasible this sort of coercion would be, but it is a potentially valuable feature.

==== Property guards ====

Something that became apparent during the development of property hooks is that a great many set hooks will be simple validation, often that a number is within a range or a string matches some criteria.  At present, those use cases are achievable with hooks but can be somewhat verbose.  Applying a pattern rule to a property would allow that rule to be applied on the set operation for that property, without having to implement it manually.

```php
class Test
{
    // These two properties have equivalent restrictions.

    public string $name is /\w{3,}/;

    public string $name { 
        set {
           if (!preg_match($value, '/\w{3,}/') {
               throw new \Exception();
           }
           $this->name = $value;
        }
    }
}
```

This more compact syntax would be considerably easier to read and maintain when used within a promoted constructor parameter, too.  Note that variable binding would not be supported in a property guard, as it makes little logical sense.

Elevating such checks to a pattern would also make the pattern more readily available to static analysis tools (IDEs or otherwise), which would then be better able to validate if a value is about to be passed to a parameter that would not satisfy the pattern (eg, because the string is too short).

(We're not sure if ''is'' or ''as'' would make more sense to use here.  That's an implementation detail we don't need to worry about until this feature is actually getting implemented.)

==== Parameter or return guards ====

In concept, parameters and returns could have a similar guard syntax to properties.  The use case is arguably smaller, but it might be possible to allow variable binding.  (Unclear.)

As an example, the following would be equivalent.

```php
function test(string $name is /\w{3,}/): string is /\w{10,}/ {
    return $name . ' (retired)';
}

function test(string $name): string {
    $name as /\w{3,}/; // Throws if it doesn't match.

    $return = $name . ' (retired)';
    $return as /\w{10,}/; // Throws if it doesn't match.
    return $return;
}
```

Naturally type-only pattern checks are entirely redundant.  It would be most useful with regex or range patterns.  However, it would allow literal matches, which is a feature that has been requested in the past:

```php
function query(array $args, string $sort is 'ASC'|'DESC') { ... }
```

==== Patterns as variables/types ====

With complex array or object patterns, especially if guards are adopted, it becomes natural to want to reuse the same pattern in multiple places.  At this time we are not sure how to do so, although it is a space we are considering.  Possibilities include (unvetted):

```php
// Wrap the pattern into an object that can be referenced, possibly with some distinguishing marker.
$naturalNum = new Pattern(int&>0);
$foo is $naturalNum;    // Would need some way to disambiguate it from a binding variable.

// Put this in the "use" section of a file.
use pattern int&>0 as NaturalNum;
$foo is NaturalNum;

// Make this exposed to other files, like a constant would be.
pattern int&>0 as NaturalNum;
$foo is NaturalNum;
```

This is an area that requires more exploration, but we mention it here for completeness.
