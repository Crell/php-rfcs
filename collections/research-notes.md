The initial idea from Derick.

```php
class Book
{
    public function __construct(
        public int $id,
        public string $title,
        public Authors $authors,
        pubilc int $year,
    ) {}
}

class Author
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}

collection Authors(int => Author) {}

collection Books(int => Book) {}

$lib = new Books();
```

In this version, a `collection` is syntax sugar for a class, just like `enum` is.  It becomes roughly equivalent to:

```php
enum KeyType {
    case int;
    case string;
}

class Authors implements Iterable, Countable
{
    private KeyType $keyType = 'int';
    private string $valueType = Author::class;
    
    public function add(int $key, Author $value) { ... }
    
    public function remove(Author $value) { ... }
}
```

The first question that springs to mind is if we can use the `$id` of each object as its key.  There's definitely a case for that, as it would let you enforce uniqueness in a custom way.  However, that also makes sense only in maps, not in sequences.  (The key of a sequence is fixed and not yours to define.)

This immediately suggests that we may want to split the collection into two: A list/sequence and a map/dict.  (I'm going to use the term Dict here to avoid confusion with the map operation.)  So let's explore those.

```php
list Authors(Author::class) {}

dict Books(int => Book) {}

dict BooksByTitle(string => Book) {}
```

The API for lists and dictss is different in important ways.  For example:

* map and filter on a list should take only the value.  On a dict, it's reasonable to also pass the key.
* Filter on a list should always reindex.  On a dict, it should never reindex.
* The add operation (whatever it is) for a list should have no opportunity to specify the index.  On a dict, it must.

That suggests that a dict should implement `ArrayAccess`, but a list should not.  Both should be countable and iterable, though.

So they are roughly equivalent to:

```php
abstract class List implements IteratorAggregate, Countable
{
    private array $values;
    private readonly string $valueType;
    
    public function count() { return count($this->values); }
    public function getIterator() { return new ArrayIterator($this->values); }
    
    public function add(mixed $value): static
    {
        // A bit more robust so we can also have lists of scalars.
        if (! $value instanceof $this->valueType) { throw new TypeError(); }
        $this->values[] = $value;
    }
}

abstract class Dict implements IteratorAggregate, Countable, ArrayAccess
{
    private array $values;
    private readonly KeyType $keyType;
    private readonly string $valueType;
   
    
    public function count() { return count($this->values); }
    public function getIterator() { return new ArrayIterator($this->values); }

    public function offsetGet(int|string $key): mixed
    {
        if ($this->keyType === KeyType::int && !is_int($key)) { throw new TypeError(); }
        if ($this->keyType === KeyType::string && !is_int($string)) { throw new TypeError(); }
        if (! $value instanceof $this->valueType) { throw new TypeError(); }
        return $this->values[$key];
    }
    public function offsetSet(int|string $key, never $value): void
    {
        if ($this->keyType === KeyType::int && !is_int($key)) { throw new TypeError(); }
        if ($this->keyType === KeyType::string && !is_int($string)) { throw new TypeError(); }
        if (! $value instanceof $this->valueType) { throw new TypeError(); }
        $this->values[$key] = $value;
    }
    
    // And the other ArrayAccess methods.
}

dict Books(int => Book) {}

// turns into:

class Books extends List 
{
    // Yes, you cannot set defaults on real readonly properties, this is just for example purposes.
    private readonly string $valueType = Book::class;
}

dict BooksByTitle(string => Book) {}

// turns into:

class BooksByTitle extends Dict 
{
    private readonly KeyType $keyType = KeyType::string;
    private readonly string $valueType = Book::class;
}

```

However, that immediately poses an issue.  Trying to *read* from a List by index is entirely fine.  It's just assigning to it by index that is problematic.  So lists want... half of `ArrayAccess`, specifically, an equivalent of `offsetGet()` and `offsetExists()`.  (`ArrayAccess` fail.)  I suspect this could be done at the engine level since these don't actually turn into PHP code; might it make sense to also split `ArrayAccess` into two interfaces, at least internally?  I dunno.  I defer to Derick.

Other questions:

* Can a trait added to a collection definition access the internal settings?  (`$values`, `$keyType`, `$valueType`).  My first thought is no, but I can see use cases for it.
* Actually, the same question applies for any methods.  This will be interesting.
* What about custom-key/derived-key cases?  That doesn't really fit with either approach above right now.  Lists have no keys, and dicts have explicit keys.  Does that mean we need a third case here?  Erf.

Thinking on this further, there's a whole bunch of different collection-based structures that would have a different interface.  Having a common "Collection" for everything is just repeating the mistakes of arrays.

For example:
* List/Sequence
* Dict
* Set
* Stack
* Queue
* Priority Queue?

... Basically, all SPL collection objects.  Whee!  Not all of that needs to be done at once, but we should be aware that is the natural extension.

There's two ways we could address the different APIs.  One is to have a series of different keywords for nominally different structures.  The other is to have a single collection keyword, and then an interface that the collection must implement one of.  The implementation of it can be automatic, probably, so it's just a marker interface.

So either:

```php
seq Books(Book) {}

set BooksByTitle(Book) {}

dict BooksByKeyword(string => Book) {}
```

Or:

```php
collection Books(Book) implements Sequence {}

collection BooksByTitle(Book) implements Set {}

collection BooksByKeyword(string => Book) implements Dict {}
```

I... don't know yet which one I like better.

Leaving that aside for a moment, what would the API for each ideally look like?  The following draws from the API of Doctrine collections and Laravel collections, but not exclusively.

| Operation                           | Seq   | Dict  | Set   | Stack | Queue | Notes                                                                                        |
|-------------------------------------|-------|-------|-------|-------|-------|----------------------------------------------------------------------------------------------|
| __Core behavior__                   |       |       |       |       |       | Without these, it's not really a collection                                                  |
| $c[] (add to end)                   | Y     | N     | N     | Maybe | Maybe | Is this sufficient, or redundant with add/push()?                                            |
| add(5)                              | Y     | N     | Y     | N     | Y     | Is this redundant with []?                                                                   |
| $v = $c[5]                          | Y     | Y     | N     | N     | N     | Should error if not set                                                                      |
| $c[5] = 'beep'                      | Maybe | Y     | N     | N     | N     | Only makes sense on Seq if the key already exists                                            |
| set(5, 'beep')                      | N     | Y     | N     | N     | N     | Redundant?                                                                                   |
| clear()                             | Y     | Y     | Y     | Y     | Y     |                                                                                              |
| hasKey($key)                        | Maybe | Y     | Y     | N     | N     | Possibly redundant with isset($c[5])                                                         |
| hasValue($idx)                      | Y     | Y     | Y     | N     | N     |                                                                                              |
| isset($c[5])                        | Y     | Y     | Y     | N     | N     |                                                                                              |
| unset($c[5])                        | Y     | Y     | Y     | N     | N     | Redundant with removeKey()?                                                                  |
| removeKey($key)                     | Y     | Y     | Y     | N     | N     | May need a different name on Seq                                                             |
| removeValue($val)                   | Y     | Y     | Maybe | N     | N     |                                                                                              |
| isEmpty(): bool                     | Y     | Y     | Y     | Y     | Y     | Does empty() make sense to use here?                                                         |
| count(): bool                       | Y     | Y     | Y     | Y     | Y     | Countable interface.                                                                         |
| push(5)                             | N     | N     | N     | Y     | N     | Is this different from add()?                                                                |
| pop(): T                            | N     | N     | N     | Y     | N     |                                                                                              |
| peek(): T                           | N     | N     | N     | Y     | N     |                                                                                              |
| id(T $val)                          | N     | N     | Y     | N     | N     | Must be implemented. Computes the key off of the value to use for uniqueness                 |
| static fromArray(array)             | Y     | Y     | Y     | Maybe | Maybe | On Seq and Set, ignores keys. On Dict, preserves keys. TypeError if invalid                  |
| __Basic behavior__                  |       |       |       |       |       | Could technically be skipped, but really should include                                      |
| keys(): array                       | N     | Y     | Y     | N     | N     | Presumably array is a reasonable return                                                      |
| values(): array                     | N     | Y     | Y     | N     | N     | Or should this return a Seq?                                                                 |
| indexOf($val): int or string        | Y     | Y     | Y     | N     | N     | Name could vary by type                                                                      |
| $c->map($fn, $targetType) *         | Y     | Y     | Y     | N     | N     | See note below                                                                               |
| $c->filter($fn) *                   | Y     | Y     | Y     | N     | N     | See note below                                                                               |
| $c->reduce($fn, $init) *            | Y     | Y     | Y     | N     | N     | See note below                                                                               |
| asArray(): array                    | Y     | Y     | Y     | Maybe | Maybe | Could be useful for debugging on Stack and Queue                                             |
| $c + $c2                            | Y     | Y     | Y     | N     | N     | Concat Seq, reindexing. Combine Dict and Set, eliminating duplicate keys.                    |
| concat()                            | Y     | Y     | Y     | N     | N     | Same as +.  I like supporting +, especially as arrays do now.                                |
| first(): ?T                         | Y     | Y     | Y     | N     | Maybe | Same as $c[0] on Seq. No equivalent on Dict or Set. On queue, alias of head()?               |
| last(): ?T                          | Y     | Maybe | Maybe | N     | N     | Only makes sense if we guarantee order on Dict and Set.                                      |
| slice($start, $len)                 | Y     | N     | N     | N     | N     | Like array_slice()                                                                           |
| $c[$start:$len]                     | Y     | N     | N     | N     | N     | A direct syntax option has been discussed for arrays before; it only makes sense on Seq.     |
| __Extended behavior__               |       |       |       |       |       | These are present in Doctrine or Laravel, but could be skipped                               |
| sort() / usort()                    | Y     | Y     | Y     | N     | N     | Could sort in place or return new. I'd favor return new, if possible.                        |
| ksort() / uksort()                  | N     | Y     | Y     | N     | N     | One could argue if Dict and Seq should have an order. In Python they do not.                 |
| findFirst(callable): ?T *           | Y     | Y     | Y     | N     | N     | Returns first element that evaluates to true.                                                |
| findLast(callable): ?T *            | Y     | Y     | Y     | N     | N     | Returns last element that evaluates to true.                                                 |
| all(callable): bool *               | Y     | Y     | Y     | N     | N     | True if callable is true for all elements.                                                   |
| any(callable): bool *               | Y     | Y     | Y     | N     | N     | True if callable is true for any element.                                                    |
| none(callable): bool *              | Y     | Y     | Y     | N     | N     | True if callable is false for all elements.                                                  |
| groupBy(callable): array *          | Y     | Y     | Y     | N     | N     | Partition collection by the value returned by callable. array, or Dict?                      |
| slice(int $from, int $count)        | Y     | N     | N     | N     | N     | Only makes sense on Seq, I think                                                             |
| chunk(int)                          | Y     | Y     | Y     | N     | N     | Split into fixed number of sizes. Could return array or Seq.                                 |
| combine(Seq): Dict                  | Y     | N     | N     | N     | N     | A Seq with keys, combine() with a Seq with values, get back a Dict. array_combine().         |
| countBy(?callable): Dict<int>       | Y     | Y     | N     | N     | N     | Unclear what the return type is. Laravel's version seems only sensible for ints/strings.     |
| diff(Collection)                    | Maybe | Y     | Y     | N     | N     | Same as array_diff().  Ignores keys on Map and Set.                                          |
| diffAssoc(Collection)               | N     | Y     | Y     | N     | N     | array_diff_assoc().                                                                          |
| diffKeys(Collection)                | N     | Y     | Y     | N     | N     | array_diff_keys()                                                                            |
| each(callable)                      | Y     | Y     | Y     | N     | N     | Similar to map, but lets you bail early by returning false.                                  |
| flatten()                           | Maybe | Maybe | Maybe | N     | N     | Only makes sense with nested collections, which makes interesting type challenges.           |
| flip()                              | N     | Maybe | Maybe | N     | N     | Very limited use unless keys can be objects.                                                 |
| implode(string $glue)               | Y     | N     | N     | N     | N     | implode().                                                                                   |
| implode(string $glue, string $join) | N     | Y     | Y     | N     | N     | Particularly useful for, say, building query strings                                         |
| intersect(... Collection)           | Maybe | Y     | Y     | N     | N     | Only works with same collection type. array_intersect().                                     |
| intersectKeys(... Collection)       | Maybe | Y     | Y     | N     | N     | Only works with same collection type. array_intersect_keys().                                |
| intersectAssoc(... Collection)      | N     | Y     | Y     | N     | N     | Only works with same collection type. array_intersect_assoc().                               |
| keyBy(callable): Map                | Y     | Maybe | Maybe | N     | N     | Unclear if this is sensible on Map or Set, as they have keys already.                        |
| nth(int $step, int $offset = 0)     | Y     | Y     | Y     | N     | N     | Creates a new collection consisting of every n-th element. Probably not needed.              |
| pipe(callable(Collection)) *        | Y     | Y     | Y     | N     | N     | Laravel has this. I'm not sure I like this approach to function concat.                      |
| pull($key)                          | Maybe | Y     | Y     | N     | N     | Returns the value for the $key, and removes from the collection.                             |
| random()                            | Y     | Y     | Y     | N     | N     | Returns a random element. No idea how we define "random". Laravel lets you request multiple. |
| reverse()                           | Y     | Y     | Y     | N     | N     | Reverses the order of elements.                                                              |
|                                     |       |       |       |       |       |                                                                                              |
|                                     |       |       |       |       |       |                                                                                              |

I've omitted some Laravel collections operations that I feel are needlessly redundant or easily replicated with other operations.  However, some of them might be faster than combining separate operations as it would require only a single iteration pass.  I don't think we need to worry about that at this stage, though.

* The signature for map, filter, reduce, and any other method that takes a callback is necessarily different in each case.

```php
Seq { 
    public function map(callable(T $value): T2, string $targetCollectionType): Seq;
}
Set { 
    public function map(callable(T $value): T2, string $targetCollectionType): Set;
}
Dict { 
    public function map(callable(T $value, int|string $key): T2, string $targetCollectionType): Dict;
}

$books = new Books();
// ...
$books->map(fn (Book $b): Author => $b->authors[0], Authors::class); // Returns Authors instance.
```

Where T is the type of the collection, and T2 is $targetType, which is the collection to build with the results.  It must be pre-defined.  For Seq, the order is preserved, and thus so are keys.  For Set, any resulting duplicates are omitted, and thus the size of the result may be smaller and reindexed from 0.  For Dict, order and keys are preserved.

`reduce()` returns a `mixed`, but also has the extra `$key` parameter in its callback.

If included, `groupBy()` is an interesting case.  (Doctrine has a limited version called "partition". Laravel has a fancy version called `chunkWhile()`.)  It could return an array or a Dict, and the values of either would necessarily be the of the type that was invoked.  So:

```php
$dict = $aSet->partition($fn);
// $dict is now a Dict instance, each element of which is a Set<T>, and the keys are whatever $fn returned.
$dict = $aDict->partition($fn);
// $dict is now a Dict instance with keys from $fn, and each element is a Dict<T> that is a subset of the original dict.
```

I don't know if I prefer a Dict or an array here.  I am kinda leaning toward Dict, as it's more powerful and easy enough to dump back to an array.  The same question applies to `chunk()`, but there it would return a Seq.

A valid question is whether `has()` and similar use strict or weak comparison.  The options I see are to go all-strict (like `match()`) or make it responsive to the file's mode.  I'd favor all-strict, honestly.

The elephpant in the room, of course, is what methods make sense to include in core.  There's dozens of reasonable operations to apply on a collection, and they're different for different people.  Even PHP's stdlib has dozens of array functions.  A universal agreement on what should be included is impossible.  The problem is that, unlike functions, there's no good way to polyfill methods on an object.  So the extension mechanism has to be thought through carefully.

I see a number of options, in no particular order:

1. "Collections are just the core basics, everything else is the user's problem."  This would be the simplest option for implementation, but also the weakest.  I would expect it to lead to lots of subtly-incompatible implementations; eg, some `map()` implementations would include a `$targetType`, but some would not, and some would name it differently (which matters now with named arguments.)  It also opens the bikeshed for "what is core basics."  Is `map()` a core basic or no?  How about `sort()`?  It's also extremely unlikely that they would be able to displace Doctrine or Laravel collections, or even be leveraged by them under the hood.
2. Include the kitchen sink.  If either Doctrine or Laravel collections have it, provide a version in core.  This would either be the most or least controversial approach.  It's hard to say which.  Of course, then we get to bikeshed the signatures of several dozen methods.  It also means a massive API in core.
3. Provide "opt-in" implementations of many/most operations.  This could be done with a marker interface, which can then be type checked, and the engine detects the interface and adds in the stock implementations.  This provides some standardization, but would still have similar bikeshed potential and requires more work for collection authors.  Eg, `implements Sortable`, and you magically get `sort()`, `usort()`, and if appropriate `ksort()`, `uksort()`, etc.
4. Same opt-in, but with... traits.  (There, I said it.)  Core could provide a bunch of traits that include one or more methods, which collection authors could `use`.  This is more natural than magic interfaces, but then cannot be typed against.
5. There's a possibility that FIG could provide a standard lib of collection traits for things not worth including in core.  I don't know if there would be interest, but the process for doing so is now in place.
6. Laravel provides a `macro()` operation that registers new functions as methods, via `__call()`.  Something like that could be possible, though I'm not sure how useful it is if collections always involve defining a type, where you could just put your own methods/traits/whatever in the first place.  It would only be useful for allowing 3rd parties to extend someone else's collection, although whether that's wise is debatable.
7. A pipe operator.  One of the advantages of pipes is that it gives method-feeling call semantics without requiring methods to be declared up front.  That would allow for a minimalist collection, as almost anything else could be built on top of it.  Of course, both pipes and PFA were declined so that seems hard to bank on, unless collections became part of the argument for pipes.

At the moment, none of these seem like a clear winner to me.

Another consideration is that any generic operations outside a collection that still operate on collections may need to know the generic information of the collection.  Ie, the key type (if relevant) and value type.  So an API for those will need to be included, too.

Then we come to the next big question: Lazy collections.  Both Doctrine and Laravel have a concept of a lazy collection.

In Laravel, they look like this:

```php
LazyCollection::make(function () {
    $handle = fopen('log.txt', 'r');
 
    while (($line = fgets($handle)) !== false) {
        yield $line;
    }
})->chunk(4)->map(function (array $lines) {
    return LogEntry::fromLines($lines);
})->each(function (LogEntry $logEntry) {
    // Process the log entry...
});
```

With a lazy collection, each operation subsequently returns a new Lazy collection, such that the actual operation doesn't happen until the very end.  (Eg, in the example above, In some cases (log files, DB results, etc.) that can be a dramatic memory savings.

Doctrine's lazy collections, by contrast, require you to extend a base class and implement a `doInitialize()` method that saves a lazy value (ie, a DB result object) to a property.

I do feel that some kind of lazy collection support is valuable, however, I am not sure how best to do it.  From an ergonomic POV, a collection would have a `fromIterable()` static method that is called with a generator and poof, you now have a lazy collection.  I don't know if that's practical to implement, however.  If not, it may require some other flag on the type (similar to how both Laravel and Doctrine use a separate base class) to indicate if it should be lazy or not.  This also potentially balloons the API surface.  However, a really good lazy collection system combined with an infinite generator working from IO gets us most of the way to functional reactive programming.  (Actually building on top of that is a task for user space, definitely.)

## Language research

For comparison, let's see what some other competing languages do.

### Python

Python has built in List, Dict, Set, and Deque types.  It has no Queue or Stack.  List has a `pop()` method so it can be used as a Stack.  Deque is used for queues.  Oh dear, do we also need to include Deque?

In Python 3, all collection types are lazy by default.  This distinction is *mostly* hidden from the developer, except when it isn't.

#### List

List's methods include:

* append (add one item)
* extend (append from an iterable, basically concat)
* insert (at a specific index)
* remove (removes a value, or ValueError if not found)
* pop
* clear
* index (basically `keyOf(value)`)
* count
* sort (sorts in place)
* reverse
* copy (shallow copy)

There's also a `del` operator, which is approximately `unset($arr[$id])`.

#### Set

Set may only contain immutable types, which is... an important observation.  Do we need to limit it to readonly classes?  It has no intrinsic order.  Its operations, as near as I can find, are:

* union
* intersection
* difference
* symmetric_difference (basically XOR)
* isdisjoint (true if two sets have no elements in common)
* issubset
* issuperset
* update (union in place)
* intersection_update (intersection in place)
* difference_update
* symetric_difference_update
* add
* remove (ValueError if not found)
* discard (no ValueError)
* pop (remove an element at random)
* clear

Several mathematical operators are also implemented for Set, such as `-` (difference) `<` (issubset), `|=` (union update in place), `|` (union).

There is also a `frozenset`, which is the same but without the mutator methods.

It's not entirely clear how Python defines uniqueness for arbitrary objects.  I'm assuming it leverages operator overloading support, but need to verify.  Sets also use `{}` wrappers rather than `[]`, just to be different.

#### Dicts

Dicts have similar assign/read operations as PHP arrays, which is fine.  Dict keys may be any immutable type, or, more properly, a hashable type.  (So maybe a `__hash` method on objects is enough?)

Dicts have several native syntax operations as well as methods:

* `$val in $dict` - array_key_exists().  (even though I'd have guessed in_array().)
* `len(dict)` - Actually works for any collection. Equivalent of count().
* `clear()`
* `get($key, $default)` - The inclusion of default is interesting
* `items()` - Returns a List of tuples of key/value pairs.
* `keys()` - returns a list
* `values()` - returns a list
* `pop(key, $default)` - Return that key and remove it
* `popitem()` - removes the last key/value pair added, even though dicts are unordered
* `update()` - array merge, essentially

There is also a "collections" package available that adds some more options, but that's not language-native.

All collection types are mutable.

### Swift

Swift also has three separate structures: Array (list/sequence), Set, and Dictionary.  All are mutable, but if assigned to a constant become immutable.  (Swift has a `var` vs `let` distinction for that.)  All three are also typed, similar to what is proposed here.

#### Array

Arrays are typed, and also when created can be pre-filled, a la `array_fill()` in their constructor, or specified as a literal.

There's a huge number of operations, listed here: https://developer.apple.com/documentation/swift/array.  Apparently Swift's answer to "which methods should be baked in?" is "all of them."

Some highlights:

* `+` - concatenate two arrays.
* `isEmpty` - This is a boolean *property*.  (Which if property hooks pass, we could also do easily.)
* `count` - Property. Number of elements.
* `capacity` - Property.  Number of elements the array could contain without being resized. (Exponential growth strategy, same as PHP.)
* `first` - Property. The first element, or nil.
* `last` - Property.  The last element, or nil.
* `randomElement()` - nil if empty.
* `append()` - I think this is just one item, but not certain.
* `+=` - Concat and assign.
* `$c[4...6] = ['A', 'B', 'C']` - Overwrite a range of values at once.  Interesting.
* `insert($value, $idx)` - Same as assigning by subscript.
* `remove($idx)` - Remove a value at a given index and return it.  Runtime error if out of bounds.
* `removeFirst()` - Remove and return the first value.
* `removeLast()` - Remove and return the last value.
* `removeSubrange(1..<4)` - Remove elements starting from index one, continuing until index 4, non-inclusive.
* `removeAll($fn)` - This looks like filter(), but operates in place.
* `popLast()` - Seems to be an alias of `removeLast()`, as far as I can tell?
* `enumerated()` - Returns a list of tuples of key and value, for use in a for loop. Not really relevant with foreach().
* `sort()` - Sorts in place.  Requires values to be of a `Comparable` type.  Also takes a comparison function, optionally.
* `sorted()` - Returns new sorted Array.
* `reverse()` - Reverses in place.
* `reversed()` - Returns a new reversed Array.
* `shuffle()` - Randomize in place.
* `shuffled()` - Returns a new randomized Array.
* `partition($fn: bool)` - Reorders in place such that all false-returning values come before true-returning values. Kinda like one step of QuickSort, I guess?
* `swapAt($idx1, $idx2)` - Swaps two values in-place.
* `contains($val)` - Bool
* `contains($fn)` - What other APIs would call `any()`, it looks like.
* `allSatisfy($fn)` - `all()` by another name.
* `first($fn)` - First element that returns true for $fn.  This is a method, and doesn't collide with the `first` property.
* `firstIndex($fn)` - Like `first()`, but returns the index, not the value.
* `last()`, `lastIndex()` - Same, but for last items.
* `index($val)` - The index where $val is, or nil.
* `min($fn)` and `max($fn)` - Allows custom comparators.
* `prefix($maxLength)` - The first `$maxLength` elements.
* `prefix($position)` - The first elements up to and including `$position`.
* `prefix($upTo)` - Same as previous, but non-inclusive.
* `prefix($while)` - The first elements, up to the first one that returns false for the callback.
* `suffix($count)` - The last `$count` elements.
* `suffix($from)` - The last elements starting from index `$from`.
* `dropFirst($k)` - New Array that skips the first `$k` elements.
* `dropLast($k)` - New array excluding the last `$k` elements.
* `drop($while)` - Returns a subsequence by skipping elements while predicate returns true and returning the remaining elements.
* `forEach($fn: void)` - Apply the `$fn` on each element.  More like `array_walk()` than `array_map()`.
* `map<T>($fn)` - The usual map operation.
* `flatMap<T>($fn)` - Concatenates the result of each map together. On the assumption that each `$fn` call returns an Array itself.
* `compactMap<T>($fn)` - Same as `map()`, but then filters out `nil` values.
* `reduce($init, $fn)` - The obvious.
* `lazy` - A property, which is the same Array but with map, filter, etc. implemented lazily.


#### Set

Sets only work on hashable types.  A hash is a numeric value.  Scalars and enums are supported by default, and there's a `Hashable` protocol (aka interface) for objects to opt-in to being set-able.  Sets are type-specific, generically.   The Hashable protocol has one method, which is passed a "Hasher", which can be used to combine values together.  Basically you'd call `hasher.combine()` with each of the properties you want to be involved in the hash, and it does the rest.  If a struct contains all hashable properties, it gets automatically Hashable with the obvious/naive implementation, so it's probably rare that it needs to be implemented custom.

Sets may be initialized from an array literal.  Bracket syntax works as expected.

Operations I can find include:

* `isEmpty` - Boolean property.
* `count` - Property. Number of elements.
* `capacity` - Property.  Number of elements the array could contain without being resized. (Exponential growth strategy, same as PHP.)
* `insert($val)` - Add a value.
* `update($val)` - Add a value.
* `remove($val)` - Returns and removes a value.  Return `nil` if not found.
* `removeFirst()` - Where "first" is not really defined because Set is unordered.  Weird.
* `removeAll()` - Obvious.
* `contains($val)` - Boolean
* `intersection($set)` - Returns a new set
* `formIntersection($set)` - Update the set rather than making a new one.
* `union($set)` - Returns a new set
* `formUnion($set)` - Update the set rather than making a new one.
* `symmetricDifference($set)` / `formSymmetricDifference($set)` - XOR.
* `subtract($set)` - All elements in `$this` that are not in `$set`.  Updates in place.
* `subtracting($set)` - All elements in `$this` that are not in `$set`.  Returns a new set.
* `$s1 == $s2` - True if both sets contain the exact same values. (Order is, I think, irrelevant.)
* `isSubSetOf($set)` - Also true if ==.
* `isSupersetOf($set)` - Also true if ==.
* `isStrictSubsetOf($set)` - False if ==.
* `isStrictSupersetOf($set)` - False if ==.
* `isDisjointWith($set)` - True if the sets have no values in common.
* `randomElement()` - One item at random.
* `min($fn)` and `max($fn)` - Allows custom comparators.
* `first($fn)` - First element that returns true for $fn.
* `firstIndex($fn)` - Like `first()`, but returns the index, not the value.
* `last()`, `lastIndex()` - Same, but for last items.
* `map<T>($fn)` - The usual map operation.
* `flatMap<T>($fn)` - Concatenates the result of each map together. On the assumption that each `$fn` call returns an Array itself.
* `compactMap<T>($fn)` - Same as `map()`, but then filters out `nil` values.
* `reduce($init, $fn)` - The obvious.
* `sorted()` - A new set, sorted.  Ony works if the values are `Comparable`.
* `shuffled()` - A new set, shuffled.

#### Dictionary

Dictionary keys may be any type that is `Hashable`.  Dictionaries have two types, the key and the value.

This seems like a possible solution to objects-as-array-keys?  Though I don't know if the original object is retrievable.  We could probably make it so.

The Dictionary literal form looks similar to PHP's but with colons.  The assignment looks exactly the same.

Once again, there's a bajillion operations.  https://developer.apple.com/documentation/swift/dictionary

Highlights include:

* `count` - A property with the number of elements.
* `capacity` - Property.
* `isEmpty` - Boolean property.
* `updateValue($k, $v)` - Sets a value at a given key.  Similar to `$d[$key] = $val`, but returns the old value, if any, using Optional (Swift's Maybe monad).
*  `$d[$k] = nil` - Unset a value.
* `removeValue($k)` - Remove a value, and return it.
* `randomElement()`
* `merge($dict)` - Merges in place.
* `filter($fn)` - `$fn` gets both key and value.
* `==` - Only possible when the key is `Hashable` and the value is `Equatable`.
* `!=` - Obvious.
* `contains($fn)`
* `allSatisfy($fn)`
* `first($fn)`
* `firstIndex($fn)`
* `mapValues($fn)` - Returns new dict.  $fn only takes the value, keys preserved.
* `map($fn)` - Returns an array.  $fn only takes the value.
* `flatMap<T>($fn)` - Concatenates the result of each map together. On the assumption that each `$fn` call returns an Array itself.
* `compactMap<T>($fn)` - Same as `map()`, but then filters out `nil` values.
* `reduce($init, $fn)` - The obvious. $fn only takes the value.
* `sorted()`
* `shuffled()`

There's also a whole parallel "Lazy" set of collections, where map, filter, etc. are implemented lazily.  I'm not entirely clear what that means.

Most of those methods seem to come from a zillion different interfaces that are implemented by all kinds of types.

Swift also has a Collections add-on library in its stdlib.  (Or maybe in the process of getting into the stdlib? Unclear.)  It includes a Deque, OrderedSet, OrderedDictionary (works kinda like PHP arrays)

### Go

#### Arrays and Slices

Go has two native list types, because of its memory design.  Arrays are a typed sequence of values, with a fixed defined size.  `[2]int` and `[3]int` are two different types.  Of note, Go types have "zero values", and an array initializes all values to the appropriate zero type.

Arrays may be indexed by a 0-based index, but there's no support for negative indexes to count from the end.

Operations include:

* `len(a)` - Number of elements.
* `a[3:6]` - Create a "slice" out of `a`, from index 3 (inclusive) through 6 (exclusive). Omitting the first number means "from the beginning."  Omitting the last number means "to the end, inclusive."

Slices are of variable size, and are a sort of window onto arrays.  They have a resizeable array internally.  

* `append(a, val)` - Add `val` to the end of `a`.  `val` is variadic.
* `cap(a)` - The current capacity of `a`, which is not the same as its size.

Slices do not have a built-in deletion operation.  Instead, you re-slice them to a new slice, like so: `slice = append(slice[:i], slice[i+1:]...)`

I haven't found much else in the way of built-in operations.  Typical Go.

#### Maps

Maps are native in Go's syntax.  They are very strongly typed.  The value may be any type.  The key may be any type that is "Comparable".  From a blog post on the go.dev site: "in short, comparable types are boolean, numeric, string, pointer, channel, and interface types, and structs or arrays that contain only those types. Notably absent from the list are slices, maps, and functions; these types cannot be compared using ==, and may not be used as map keys."

Go maps are technically reference types, so always start as `nil`.  They require separate initialization using `make()`.  Maps are explicitly unordered and the order of returns is undefined.

Of note, Go types have "zero values", and a missing key evaluates to the zero value of the map's value type.  

Operations include

* `len(m)` - Number of elements
* `m["foo"] = "bar"` - Basic assignment.
* `delete(m, key)` - Remove a key from the map. No error on missing key.
* `_, ok := m["route"]` - Uses Go's funky multi-return to check existence. This is the `array_key_exists()` equivalent.

As is typical for Go, the built-in API is minimal and anything even slightly interesting is left to user-space to figure out.

#### Set

Go lacks a native Set type.  Instead, there are known, documented ways to use a Map as a set, since the key can be so flexible.  

I really, really don't like Go's collections...


### Kotlin

Kotlin has two versions of each of the three standard collection types: A mutable one and a read-only one.  They are all typed.  The immutable ones are covariant, but not the mutable ones.  So `List<Square>` can go into `List<Rectangle>` but only if it's an immutable collection.

Specifically, the mutable versions all inherit from the immutable version, and from a `MutableCollection` type.  (I'm not clear on the details.)  There's also an immutable `Collection` type everything inherits from.  You can type parameters with `Collection<String>` and `MutableCollection<String>`.

Apparently, these types are all interfaces, and there are multiple possible implementations of each available.

Kotlin has the idea of "structural equality."  This is basically `==` as PHP understands it, and behaves by default much the same, I think.  However, you can override the `equals()` method on an object to change how the comparison works.

There is *also* a `Comparable` interface with a `compareTo()` method for user-defined types.  I think this is used only internally, whereas `equals()` is an operator overload.  Why they're not unified, I don't know.  There's also "natural" order, which is the usual numeric and lexical ordering.

All three types have "builder" operators, which let you create a mutable collection, populate it within a block, and then convert the result to an immutable version.  Eg:

```Kotlin
val map = buildMap { // this is MutableMap<String, Int>, types of key and value are inferred from the `put()` calls below
    put("a", 1)
    put("b", 0)
    put("c", 4)
}
```

Different collection types may be shallow cloned mutably or immutably, including to each other.  So for instance:

```Kotlin
aSet.toList()
aSet.toMutableList()
aList.toSet()
aList.toMutableSet()
```

All collections are Iterators, which means to loop over them with `while` you must call `c.iterator()`, which returns a forward-only cursor object.  A `for` loop does it automatically (basically the same as PHP).  There's also a `forEach` method that is kinda like `array_walk()`.  A bidirectional cursor can be gotten using `c.listIterator()`.



Kotlin also has `Sequence`s, which are basically generators.

There are a vast of common operations available to all collection types (I'm modifying the syntax a bit here to avoid having to explain Kotlin weirdness).  Most of these are defined as "extension functions" rather than methods, but that's not a distinction that exists in PHP:

* `c.map(fn)` - Standard map.  `fn` is passed just the value.
* `c.mapIndexed(fn)` - `fn` is passed `(idx, val)` as separate arguments.  Not clear what this means for a set.
* `c.mapNotNull(fn)` / `c.mapIndexedNotNull(fn)` - Same as above, but auto-filters null results.
* `m.mapKeys(fn)` `m.mapValues(fn)` - For Maps specifically, allows transforming just the keys or just the values. `fn` gets both passed.
* `c.zip(col2)` / `c zip col2` - The usual zip operation, returns an immutable List of Pair objects. `zip()` also takes a transformation callback.  I'm not clear how the result is different from `mapIndexed()`, though.
* `c.associateWith(c2)` - produces a Map; basically `array_combine()`.  But also allows a callback to produce the map values off of the keys in `c`.
* `c.associateBy(keyFn, valueFn)` - `valueFn` is optional.  Passes each value to the callbacks, producing a Map.  I actually have use for this.
* `c.associate(fn)` - `fn` returns a Pair, which are the k/v of the resulting Map.
* `c.flatten()` - Flattens nested collections into a List.
* `c.flatMap(fn)` - Same as `map()` followed by a `flatten`().
* `c.joinToString()` - Basically `implode()`, but has args for separator, prefix, and postfix. Also has a limit, and a truncation marker.
* `c.joinTo(str)` - Same as `joinToString()`, but sticks `str` at the beginning of the string.
* `c.filter(fn)` - The obvious. Returns List for List and Set.  Returns Map for Map. On Map, is passed both key and value.  I think.
* `c.filterIndexed(fn)` - Same, but `fn` gets both key and value.
* `c.filterNot(fn)` - Keeps elements if they return false instead of true.
* `c.filterIsInstance<Type>` - Keeps elements if they are of `Type`.
* `c.filterNotNull()` - Returns non-null values only.
* `c.partition(fn)` - Returns 2 Collections, those that match `fn` and those that do not.
* `c.any(fn)` - True if `fn` is true for any element.  With no `fn`, true if non-empty list.
* `c.all(fn)` - True if `fn` is true for all elements.  With no `fn`, true if empty list.
* `c.none(fn)` - True if `fn` is true for no elements.
* `c.groupBy(fn)` - Returns a Map, keyed by the result of `fn`.
* `c.groupingBy(fn)` - I don't really understand this, but it's for chaining, I think?
* `c.slice(1..3)` / `c.slice(0..4 step 2)` - Not sure how this works on non-lists?
* `c.slice(setOf(1, 5, 2))` - Returns the first, then 5th, then 2nd element. Again, not sure how this works on non-list.
* `c.take(2)` / `c.takeLast(2)` - Return the first two / last two elements. Non-destructive.
* `c.drop(2)` / `c.dropLast(2)` - Return everything except the first two / last two elements. Non-destructive.
* `c.takeWhile(fn)`, `c.takeLastWhile(fn)`, `c.dropWhile(fb)`, `c.dropLastWhile(fn)` - Does those things while `fn` is true.
* `c.chunked(3)` - Break into chunks of size 3.  Can also pass a predicate/fn to map each chunk.
* `c.windowed(3)` - Returns a List of each ordered 3-element subset of the list.  Unclear what it does on non-list.
* `c.elementAt(idx)` - Value at index.  On Set, what the index is depends on the implementation but there is always one. Exception if not defined.
* `c.elementAtOrNull(idx)` - Same, but return `null` if not found.
* `c.elementAtOrElse(idx, fn)` - Same, but invoke `fn` if not found and use that. 
* `c.first()` / `c.last()` - First/last elements.
* `c.first(fn)` / `c.last(fn)` - First/last elements where `fn` is true. Exception if none match.
* `c.firstOrNull(fn)` / `c.lastOrNull()` - Same things, but with `null` defaults.
* `c.find(fn)` / `c.findLast(fn)` - Aliases of previous.
* `c.firstNotNullOf()` /  `c.firstNotNullOfOrNull()` - Combination of map and first, but short-circuits (I presume).
* `c.random()` / `c.randomOrNull()` - Optionally pass a randomness source. First one throws on empty list.
* `c.contains(val)` `val in c` - true if the value exists, false otherwise.
* `c.containsAll(list2)` - true if all elements of the list are present.
* `c.isEmpty()` / `c.isNotEmpty()` - Obvious.
* `c.sorted()` / `c.sortedDescending()` - Returns new collection
* `c.sortedWith(fn)` - Sort with custom comparator.
* `c.sortedBy(fn)` / `c.sortedByDescending(fn)` - I don't understand how this is different than `sortedWith()`.
* `c.reversed()` - Obvious.
* `c.asReversed()` - Kind of a reference version of the previous.  Faster if the list is not going to change.
* `c.shuffle()` - Randomize order in place.
* `c.shuffled()` - Returns new in random order.
* `c.minOrNull()` / `c.maxOrNull()` - Obvious
* `c.average()` - Obvious. Unclear what it does on non-numeric lists.
* `c.sum()` - Obvious.
* `c.count()` - Obvious.
* `c.maxByOrNull(fn)` / `c.minByOrNull(fn)` - Basically map on `fn`, then `max()`, but returns the original value.
* `c.fold(init, fn)` - The usual, fold-left.
* `c.reduce(fn)` - Uses the first value as the init.
* `c.foldRight(init, fn)` / `c.reduceRight(init, fn)` - Same, but starts at the end of the list and goes backwards.
* `orNull()` versions of all fold/reduce methods - Returns null on empty lists instead of an exception.
* `foldIndexed(init, fn)` and all the others - `fn` gets the index, too.

On mutable collections only:

* `c.add(item)` - Append to the end.
* `c.addAll(c2 or array)` - Concatenates values from an iterable, sequence, or array.
* `c.addAll(idx, c2 or array)` - Splices values into the list, starting at `idx`.
* `c.remove(val)` - Remove `val`.
* `c.removeAll(c2)` - Remove everything in `c2` from `c`.  Can also take a `fn` filter.
* `c.retainAll(c2)` - Remove everything except what's in `c2`.  Can also take a `fn` filter.
* `c + item` - Append; returns read-only version.
* `c + c2` - Concat; returns read-only version.
* `c - item` - Removes one item, returns read-only version.
* `c - c2` - Removes all items in `c2`, returns read-only version.
* `c += item` / `c += c2` / `c -= item` / `c -= c2` - The obvious, with some caveats around mutable vs immutable versions.
* `c.clear()` - Empty the collection

#### List

Lists are created with the `listOf("a", "b", "c")` keyword.  If no values are provided, you can provide the type generically. `listOf<String>()`.  There's also `mutableListOf`.

Lists may also be created with `List(3, fn)`, where the fn callback initializes all values, using `it` as a magic variable name for their index.

List-specific operations include (in addition to the huge list above):

* `size` - Property
* `lastIndex` - Property. Equal to `size - 1`.
* `l.get(2)` - Get element 2 (0-based)
* `l.getOrElse(idx, fn)` / `l.getOrNull(idx)` - The usual.
* `l[2]` - Same as previous.
* `l.indexOf(val)` - Returns the key where `val` is found.
* `l1 == l2` - True if the lists are the same size and each index is structurally equal (see above).
* `l.subList(start, end)` - Return a new fragment of the list.
* `l.indexOf(val)` / `c.lastIndexOf(val)` - Obvious.
* `l.indexOfFirst(comparison)` / `c.indexOfLast(comparison)` - Obvious.
* `l.binarySearch(val)` - Faster way to search for the idx of a value, assuming the list has been sorted.
* `l.binarySearch(val, fn)` - Custom comparison function if the values are not comparable. Value is "found" if the comparison == 0.
* `l.add(idx, val)` - Sets the value at the given idx to val.
* `l[1] = val` = Also sets the value at a given idx.
* `l.fill(val)` - Replace all positions with `val`.
* `removeAt(idx)` - Remove the value at a key.
* `l.union(l2)` - Union, returns new set, with dupes removed.
* `l.intersect(l2)` - Intersect, returns new set, with dupes removed.
* `l.subtract(l2)` - Returns set with values in s that are not in s2.


#### Set

Sets are created with the `setOf("A", "B", "C")` keyword, or `mutableSetOf`.  The same empty-generic caveat applies.

Two sets are equal if they are the same size and there is a structurally equal element in each list.  Sets have no order, although some implementations do or don't.  I think you can use any value in a set, as long as it can be structurally compared.

* `s.toSet()` - Shallow copy to immutable set.
* `s.union(s2)` - Union, returns new set.
* `s.intersect(s2)` - Intersect, returns new set.
* `s.subtract(s2)` - Returns set with values in s that are not in s2.

#### Map

Map doesn't actually inherit from `Collection`, because it is generic over two types.

Maps are created with the `mapOf("key1" to 1, "key2" to 2, "key3" to 3, "key4" to 1)` syntax, or `mutableMapOf`.  The same empty-generic caveat applies.

Maps are unordered, and equal if there are structurally equal values at all keys.

Operations include:

* `m.get(key)` / `m[key]` - Retrieve value by key.  Exception if not found.
* `m.getOrElse(key, fn)` / `m.getOrDefault(key, val)` - Obvious by now.
* `m.keys` - Returns Set of all keys.
* `m.values` - Returns collection (list?) of values.
* `m.filter(fn)` - Returns filtered map. `fn` gets both key and value.
* `m.filterKeys(fn)` - Returns filtered map. `fn` only gets the key.
* `m.filterValues(fn)` - returns filtered map. `fn` only gets the value.
* `m + m2` - Returns combined map. In case of matching keys, right side wins.
* `m + Pair` - Adds a single key/value to the map, and returns.
* `m - key` - Returns a new map, without the `key` entry.
* `m - list` - Returns a new map, without any of the keys in `list`.

On mutable maps only, there's also:

* `m.put(key, value)` - What it says on the tin.
* `m.putAll(m2)` - Updates multiple keys in place.
* `m += m2` - Same as `putAll(m2)`.
* `m[3] = 5` - Same as `put(3, 5)`.
* `m.remove(key)` - Removes in place.
* `m.values.remove(val)` - Removes a value. the `values` property apparently still links back to the map?
* `m -= key` - Same as `remove(key)`


### Javascript

Javascript has separate Array, Set, and Map objects.  Nearly all the functionality is on Array, though, even in cases where it would be sensible to also have it on the others (like map or filter).

#### Array

Javascript Arrays are objects.  Trying to write a string key will set an object property.  Integer keys are still properties, but used as an array.  Keys are not forced to be sequential.  Some operations skip empty slots, others treat them as `undefined`, based on when the method was added.  Basically, they're just as stupidly designed as PHP arrays.

Operations include:

* `a.length` - Property.  Can also be written to in order to expand or contract the array.
* `a.concat(a2)` - Returns a new array with values from both.
* `a.copyWithin(target, start, end)` - Copy part of an array over another part of the array. Modifies in place and returns self.
* `a.entries()` - Returns iterator of k/v pairs.
* `a.every(fn)` - True if `fn` is true for all elements, false otherwise.
* `a.fill(value, start, end)` - Fill an array (or subset of an array) with a value.  Modifies in place and returns self.
* `a.filter(fn)` - Shallow copy to new array. `fn` gets just the value.
* `a.find(fn)` / `a.findLast(fn)` - Returns first element where `fn` is true, undefined on not-found.
* `a.findIndex(fn)` / `a.findLastIndex(fn)` - Returns index of first element where `fn` is true, -1 on not-found.
* `a.flat(depth)` - Recursively flattens an array, up to depth.
* `a.flatMap(fn)` - Equivalent to `a.map(...args).flat()`.
* `a.forEach(fn)` - Calls `fn` on each element, no return.
* `Array.from(arrayLike, mapFn)` - Array constructor, can map values on the way in.
* `a.includes(val, fromIndex)` - True if array has `val`.
* `a.indexOf(val)` - Return index of first `val`, or -1.
* `a.join(separator)` - join into an array.
* `a.keys()` - Returns iterator of all keys.
* `a.lastIndexOf(val)` - Return index of last `val`, or -1.
* `a.map(fn)` - Returns new array, mapped.
* `a.pop()` - Remove and return last element.
* `a.push(val)` - Add element in-place, returns the new length.
* `a.reduce(fn, init)` - Typical reduce.
* `a.reduceRight(fn, init)` - Reduce from the right.
* `a.reverse()` - Reverses array in-place, returns self.
* `a.shift()` - Remove and return the first element.
* `a.slice(start, end)` - Returns shallow copy of array subset.
* `a.some(fn)` - True if at least one element returns true from `fn`.
* `a.sort(fn)` - Sorts in place, optional comparator.
* `a.splice(start, count, ...new)` - Removes elements from the array, optionally replacing with new vals.  Operates in place.
* `a.toLocaleString(locale)` - I don't really understand this, honestly.
* `a.toReversed()` - Returns copy of the array with elements reversed.
* `a.toSorted(fn)` - Returns copy of the array with elements sorted.
* `a.toSpliced(start, count, ...new)` - Returns copy of the array with elements spliced.
* `a.toString()` - Seems equivalent to `a.join(',')`.
* `a.unshift(...vals)` - Push elemens on to beginning of array, return new length.
* `a.values()` - Return array iterator of values.
* `a.with(idx, val)` - Return new array, with `idx` set to `val`.

And "experimentally" (not yet in all browsers):

* `fromAsync(arrayLike, mapFn)`
* `group(fn)` - Returns an object (pseudo-map) of arrays, keyed by the result of `fn`. Only if keys are strings.
* `groupToMap(fn)` - Returns an object (pseudo-map) of arrays, keyed by the result of `fn`. Works for any key type.

#### Set

Sets are ordered by insertion order.  They work on any value type, using SameValueZero.

Operations include:

* `s.add(val)` - Add a value, no op if it's already there.
* `s.clear()` - Remove all values.
* `s.delete(val)` - Remove value from the set, no op if not there.
* `s.entries()` - Returns set iterator with the same val for both key and value.
* `s.forEach(fn)` - Call `fn` on each element.  fn gets the value twice, plus the whole set, as args.
* `s.has(val)` - True if found, false if not.
* `s.keys()` - Alias of `s.values()`.
* `s.values()` - Returns set iterator of values.


#### Map

Javascript Objects are basically maps, and historically have been used that way much like PHP arrays.  More recently, Javascript has added a Map object, which is more purpose-built and has less baggage.  It also supports arbitrary types as keys, including objects and functions.  Maps are ordered by insertion.

Keys in Maps are compared using "Same value zero equality", which, if I read the docs right, is basically `===`.

`[]`-based syntax does not work on Maps, because it gets confused with object property manipulation.  Because Javascript.

There is a haphazard set of APIs to convert between Maps and arrays of 2-value arrays.

Operations include:

* `m.size` - Property
* `m.clear()` - Empty the map
* `m.forEach(fn)` - Call `fn` with each key/value pair.
* `m.get(key)` - Return value or undefined.
* `m.set(key, val)` - Sets the value.
* `m.has(key)` - True if found, false if not.
* `m.keys()` - Returns all keys as an iterator object.
* `m.values()` - Returns all values as an iterator object.
* `new Map([...m1, ...m2])` - Creates an array of k/v from both maps, then makes a new map out of that.

### Rust

## Language summary

| Language/Tool | Seq | Map | Set | Dequeue | Scope      | Immutable versions | Lazy version |
|---------------|-----|-----|-----|---------|------------|--------------------|--------------|
| Doctrine      | N   | Y   | N   | N       | Expansive  | N                  | Y            |
| Laravel       | N   | Y   | N   | N       | Expansive  | N                  | Y            |
| Python        | Y   | Y   | Y   | Y       | Minimalist | N                  | Y            |
| Swift         | Y   | Y   | Y   | N       | Expansive  | Y (indirectly)     | Y            |
| Go            | Y   | Y   | N   | N       | Minimalist | N                  | N            |
| Kotlin        | Y   | Y   | Y   | N       | Expansive  | Y                  | N            |
| Javascript    | Y   | Y   | Y   | N       | Expansive  | N                  | N            |

So if we want to go based on what our peer languages and existing libraries do, then it seems we should:

* Include separate Seq, Map, and Set constructs.
* Dequeue is optional, but nice-to-have.  (There's also an old existing RFC for it: https://wiki.php.net/rfc/deque)
* Since PHP lacks a sideways post-code object extension mechanism, we need to go expansive with the API. (i.e., include most practical operations out of the box.)
* We do not need dedicated immutable versions, unless we decide that's easy to do.
* We should strongly consider if we can build a lazy version of each collection type.

The other big challenge is the type.  Some operations naturally would produce a collection of a different type, which, lacking generics, gets hard.  It would necessitate all of those operations specifying what pre-defined type to produce (as in the map examples further up).  That would be... unfortunate, but I don't see a way around it.  Ideally, we could design it in a way that makes that explicitness optional in the future, as the language evolves.  Fortunately, upon review, I *think* that would apply only to the various `map` variants and any `toOtherCollectionType()` methods.  So maybe this is just an acceptable wart.

Several languages (Swift, Kotlin, and Javascript) all use the `*ed` pattern to differentiate between modify-in-place and create-new patterns.  I quite like that, and we should do it.  The only question is if we should do it across the board (including things like map and filter), or only on selected operations like sort.  (All three of those languages did it only selectively.)

Several languages (Python, Swift, Kotlin) include at least some level of operator overloading for collection types, in addition to method variants.  I feel strongly that we should do the same.

In order to use non-primitives in a Map, or to have Sets at all, there must be a way to produce a reliable hash of an object.  An unhashable object cannot be put into a Map key or Set.  A default hash that just recursively uses all properties of the object is reasonable, but in practice I think we are going to need a way to override that for custom objects.  That could be a `Hashable` interface or a `__hash()` magic method.  Both have pros and cons.

We need to decide if we want ordered or explicitly unordered Map and Set.  It would likely be more PHP-ish to have them ordered, so they're more similar to arrays, but there may be arguments against that as well (eg, performance).

In order to sort non-primitive collections, we *could* get away with providing a comparator as we do now.  (Basically merge `sort()` and `usort()`.)  However, I also feel this is yet another argument in favor of proper native comparison methods on objects, in the vein of Jordan LD's previous RFC.  We're going to have to add that sooner or later, IMO.

We will need an explicitly defined API for getting from one collection type to another.  Eg, `Seq::toMap($type)` will return a map with all the values from the sequence and their sequence index as the key.  `Map::toSeq()` will return a sequence with all the values from the map, but all keys ignored.  Etc.  If we have lazy collections, we'll need to figure that out, as well.

### Lazy collections

Lazy collections could be extremely useful; in practice, they'd be essentially wrapping a generator into a collection shell, so that we can operate with them the same way.  (Just like `foreach()` does.)  However, not all operations on materialized collections are sensible on lazy collections.  Sorting, for instance, makes little sense on a lazy collection, whereas `map`, `filter`, and `reduce` do.  `indexOf` most likely would not.  `count()` might make sense depending on the circumstances, Etc.

That, to me, suggests that the functionality should be grouped into high-coehsion interfaces (akin to `IteratorAggregate`, and then we provide C-optimized versions of the materialized variant.  At that point, we may be able to punt lazy collections to user space, something like:

```php
class MyLazyBooks implements IteratorAggregate, Mappable, Filterable
{
    public function __construct(private \Generator $generator) {}

    public function map(\Closure $fn, string $targetType) {
        return new $targetType(function () {
            foreach ($this->generator as $v) {
                yield $fn($v);
            }
        });
    }

    public function filter(\Closure $fn) {
        return new $targetType(function () {
            foreach ($this->generator as $v) {
                if ($fn($v)) {
                    yield $v;
                }
            }
        });
    }

    // ...
}
```

That said, those certainly look generic enough that they could be baked into the system, too, and probably faster than in user space.  So, maybe it does make sense to have both `Seq` and `LazySeq` in core?
