# Pattern matching research notes

How do other languages handle pattern matching, and in particular extracting variables vs using variables to form the pattern?

## Python

See: https://peps.python.org/pep-0636/

Python only does pattern matching on a `match` statement.

Bare variables in a pattern are assigned to.

```python
match command.split():
    case ["quit"]:
        print("Goodbye!")
        quit_game()
    case ["look"]:
        current_room.describe()
    case ["get", obj]:
        character.get(obj, current_room)
    case ["go", direction]:
        current_room = current_room.neighbor(direction)
```

It's also possible to match a series of trailing items.

```python
match command.split():
    case ["drop", *objects]:
        for obj in objects:
            character.drop(obj, current_room)
```

Patterns may be ORed together:

```python
match command.split():
    ... # Other cases
    case ["north"] | ["go", "north"]:
        current_room = current_room.neighbor("north")
    case ["get", obj] | ["pick", "up", obj] | ["pick", obj, "up"]:
        ... # Code for picking up the given object
```

And you can have sub-patterns just in-line:

```python
match command.split():
    case ["go", ("north" | "south" | "east" | "west")]:
        current_room = current_room.neighbor(...)
        # how do I know which direction to go?
```

Patterns may have conditionals:

```python
match command.split():
    case ["go", direction] if direction in current_room.exits:
        current_room = current_room.neighbor(direction)
    case ["go", _]:
        print("Sorry, you can't go that way")
```

(`_` is the wildcard.)

As far as I can tell, there's no way to use a variable for a pattern element.

## Rust

See: https://doc.rust-lang.org/book/ch18-03-pattern-syntax.html

Rust supports both `match` and `if let` syntax for patterns.  They can also be used in function parameters to decompose a compound value.

```rust
fn print_coordinates(&(x, y): &(i32, i32)) {
    println!("Current location: ({x}, {y})");
}

fn main() {
    let point = (3, 5);
    print_coordinates(&point);
}
```

Function params, `let` statements, and `for` loops only take "irrefutable" patterns, that is patterns that cannot not-match.

Patterns also use `if` suffixes in `match` statements as additional guards, but that makes exhaustiveness checks impossible.

```rust
    let num = Some(4);

    match num {
        Some(x) if x % 2 == 0 => println!("The number {x} is even"),
        Some(x) => println!("The number {x} is odd"),
        None => (),
    }
```

That appears to be how Rust wants you to handle dynamic bits of a pattern:

```rust
fn main() {
    let x = Some(5);
    let y = 10;

    match x {
        Some(50) => println!("Got 50"),
        Some(n) if n == y => println!("Matched, n = {n}"),
        _ => println!("Default case, x = {x:?}"),
    }

    println!("at the end: x = {x:?}, y = {y}");
}
```

Rather than matching against `Some(%y)` or similar prefix, the check against `y` is moved to the `if` clause.

However, Rust does let you use sub-patterns using `@`.

```rust
enum Message {
    Hello { id: i32 },
}

let msg = Message::Hello { id: 5 };

match msg {
    Message::Hello {
        id: id_variable @ 3..=7,
    } => println!("Found an id in range: {id_variable}"),
    Message::Hello { id: 10..=12 } => {
        println!("Found an id in another range")
    }
    Message::Hello { id } => println!("Found some other id: {id}"),
}
```

### C#

See: https://learn.microsoft.com/en-us/dotnet/csharp/fundamentals/functional/pattern-matching

C# has an `is` operator that seems very similar to what we're proposing for PHP.  There's also a `switch` expression that is basically PHP's `match`.  In `switch`, there's again a `_` pattern to indicate a wildcard.

It does include a `not` prefix on a pattern to negate it.

It also has an object pattern syntax, which seems to omit the type.

```text
public decimal CalculateDiscount(Order order) =>
    order switch
    {
        { Items: > 10, Cost: > 1000.00m } => 0.10m,
        { Items: > 5, Cost: > 500.00m } => 0.05m,
        { Cost: > 250.00m } => 0.02m,
        null => throw new ArgumentNullException(nameof(order), "Can't calculate discount on null order"),
        var someObject => 0m,
    };
```

There's also a list pattern, similar to the array pattern syntax we're looking at.

There's an oddity in the syntax, though, where the value to bind is listed separately.  Or something.  I don't quite know how to read it.

```text
int? maybe = 12;

// What the heck
if (maybe is int number)
{
    Console.WriteLine($"The nullable int 'maybe' has the value {number}");
}
else
{
    Console.WriteLine("The nullable int 'maybe' doesn't hold a value");
}
```

The docs include a [really nice tutorial](https://learn.microsoft.com/en-us/dotnet/csharp/fundamentals/tutorials/pattern-matching) that shows going all-in on pattern matching with `switch`.  It may be good to port that to PHP just to show what is possible, though it does use some patterns like range that we're likely skipping for now.

`_` is a wildcard.

As far as I can tell, there is no concept of sub-patterns, or variables in the pattern itself.

## Swift

See: https://docs.swift.org/swift-book/documentation/the-swift-programming-language/patterns/

Swift again includes both an `is` and a `switch` syntax, and like Rust uses patterns internally as part of a for[eah] loop.  Nothing new or interesting here.

Swift also includes an `as` operator that only works with type patterns.  It can be used either with an `is` or on its own.  To steal the example from the docs:

```swift
for thing in things {
    switch thing {
    case 0 as Int:
        print("zero as an Int")
    case 0 as Double:
        print("zero as a Double")
    case let someInt as Int:
        print("an integer value of \(someInt)")
    case let someDouble as Double where someDouble > 0:
        print("a positive double value of \(someDouble)")
    case is Double:
        print("some other double value that I don't want to print")
    case let someString as String:
        print("a string value of \"\(someString)\"")
    case let (x, y) as (Double, Double):
        print("an (x, y) point at \(x), \(y)")
    case let movie as Movie:
        print("a movie called \(movie.name), dir. \(movie.director)")
    case let stringConverter as (String) -> String:
        print(stringConverter("Michael"))
    default:
        print("something else")
    }
}
```

In this case, it's doing type casting as well as pattern matching.  If the pattern doesn't match, it's just false.  If it does match, the variables get cast to the specified types.

Given that PHP is a lot more free with its types, I'm not sure that is useful for us.

Again, I find no evidence that it supports variables in the pattern itself.

## F#

See: https://learn.microsoft.com/en-us/dotnet/fsharp/language-reference/pattern-matching

F# seems similar to what we've already seen here.  However, it does have support for referencing constants.  I'm not clear if the other languages listed support that.

```fsharp
// This is how you declare a constant in F#, basically.
[<Literal>]
let Three = 3

let filter123 x =
    match x with
    | 1 | 2 | Three -> printfn "Found 1, 2, or 3!"
    // The following line contains a variable pattern.
    | var1 -> printfn "%d" var1

for x in 1..10 do filter123 x
```

Note that the `var1` arm is a bind, not using a variable.

You can also match against custom types (classes/structs), with one or more fields.  With Record patterns or list patterns you can bind variables:

```fsharp
let matchShape shape =
    match shape with
    | Rectangle(height = h) -> printfn $"Rectangle with length %f{h}"
    | Circle(r) -> printfn $"Circle with radius %f{r}"
```

There's also an `as` operator, but from the docs, I don't understand what it's doing:

> The `as` pattern is a pattern that has an `as` clause appended to it. The `as` clause binds the matched value to a name that can be used in the execution expression of a `match` expression, or, in the case where this pattern is used in a `let` binding, the name is added as a binding to the local scope.

```fsharp
let (var1, var2) as tuple1 = (1, 2)
printfn "%d %d %A" var1 var2 tuple1
```

As a functional language, besides the usual patterns everyone seems to have, there's also a `cons` pattern to split a list into a head and tail.

```fsharp
let list1 = [ 1; 2; 3; 4 ]

// This example uses a cons pattern and a list pattern.
let rec printList l =
    match l with
    | head :: tail -> printf "%d " head; printList tail
    | [] -> printfn ""

printList list1
```

This is probably not useful for us, unless introduced for lists generally.

`_` is again the wildcard.

Once again, no indication of support for variables in the pattern itself.

## Ruby

See: https://docs.ruby-lang.org/en/master/syntax/pattern_matching_rdoc.html

Ruby has only `match`-style pattern matching, like Python.  Though it's spelled `case`.

```ruby
config = {db: {user: 'admin', password: 'abc123'}}

case config
in db: {user:} # matches subhash and puts matched value in variable user
  puts "Connect with user '#{user}'"
in connection: {username: }
  puts "Connect with user '#{username}'"
else
  puts "Unrecognized structure of config"
end
# Prints: "Connect with user 'admin'"
```

Normally a `case` statement uses `when` for each arm, which is a more traditional `===` match.  Mixing `in` and `when` in the same `case` is not allowed.

Otherwise, the usual assortment of patterns are supported: array, hash, `|`-orded, types (which are objects), etc.  As well as variable binding.

```ruby
case [1, 2]
in Integer => a, Integer
  "matched: #{a}"
else
  "not matched"
end
#=> "matched: 1"

case {a: 1, b: 2, c: 3}
in a: Integer => m
  "matched: #{m}"
else
  "not matched"
end
#=> "matched: 1"
```

However, Ruby *does* have the ability to reference a variable as part of the pattern!  They call it `variable pinning`, and it uses a `^` prefix.

```ruby
expectation = 18
case [1, 2]
in ^expectation, *rest
  "matched. expectation was: #{expectation}"
else
  "not matched. expectation was: #{expectation}"
end
```

It even supports arbitrary expressions as pinnable.

```ruby
a = 1
b = 2
case 3
in ^(a + b)
  "matched"
else
  "not matched"
end
#=> "matched"
```

Ruby also includes `if` guards, which function essentially the same as in Rust.  (There's also `unless`, just to make life difficult, which we can ignore.)

```ruby
case [1, 2]
in a, b if b == a*2
  "matched"
else
  "not matched"
end
#=> "matched"

case [1, 1]
in a, b if b == a*2
  "matched"
else
  "not matched"
end
#=> "not matched"
```

## Scala

See: https://docs.scala-lang.org/tour/pattern-matching.html

Scala works essentially the same as everything else here, just with slightly different spelling.  Patterns are only available in `match` clauses, it seems.

Variable binding requires a `@` prefix.

```scala
def goIdleWithModel(device: Device): String = device match
  case p @ Phone(model) => s"$model: ${p.screenOff}"
  case c @ Computer(model) => s"$model: ${c.screenSaverOn}"
```

Otherwise, there's nothing new here.

## Conclusion

The feature set seems remarkably consistent across languages.  Everyone has a `match`/`switch` or equivalent.  Matching against types, tuples, lists, hashmaps, and objects are supported, with variable binding in just about everything.  Several languages have range support (`>200`, etc.), but seemingly not all.

Of note, no language includes the ability to define a pattern and save it to a variable to be used later.  And only one, Ruby, has support for variable pinning.

That suggests if we want to skip variable pinning for now, it wouldn't hurt us much.  (If Rust can get away without it, we probably can, too.)  That said, if it's simple enough to implement using a `^` prefix rather than the godawful `@()` syntax, that would be a nice addition, and would allow global constants to be used.  (There's no way to do so otherwise.)
