# Generics use cases in various languages

## General design model

### Kotlin

* Generic classes and functions.
* Type inference.
* Runtime erased.

### Rust

* Generic types and functions
* Monomorphized (compile time generated type-specific implementations)

### C#

* Generic classes and functions.
* Reified generics (runtime genericity).
* Supported by reflection.
* Different typed versions of a class have separate static properties.

### Java

* Generic classes.
* Type inference.
* Cannot be generic over primitive type, only boxed (eg, `Integer` object on `int`)
* Runtime erased.
* Full compile time type safety not achieved.

### Typescript

* Generic classes and functions.
* Type inference.
* Runtime erased (lost when compiling to Javascript).

## Examples

### Functions

#### Kotlin

```kotlin
fun <T> takeAction(target: T): T {
    return target
}

val aDouble = 3.14
val result = takeAction<Double>(aDouble)
// or
val result = takeAction(aDouble)
```

#### Rust

```rust
fn take_action<T>(target: T) -> T {
    return target;
}

let result = take_action::<f64>(a_double);
// or
let result = take_action(a_double);
```

#### C#

```csharp
// N/A
```

#### TypeScript

```typescript
function takeAction<T>(target: T) {
  return target;
}

let aDouble = 3.14;
let result = takeAction<number>(aDouble);
// or
let other = takeAction(aDouble);
```

### Class/type generic over all types

The class is generic over any type supported by the language.

#### Kotlin

```kotlin
class Envelope<M>(private var message: M) {
    fun get(): M = message

    fun set(newMessage: M) {
        message = newMessage
    }
}

val e = Envelope<String>("hello")
// or
val e = Envelope("hello")
```

#### Rust

```rust
struct Envelope<M> {
    message: M,
}

impl <T> Envelope<T> {
    fn get(self) -> T {
        self.message
    }
    
    fn set(&mut self, new_message: T) {
        self.message = new_message
    }
}


let mut e = Envelope::<String>{message: "hello".to_string()};
e.set("goodbye".to_string());
println!("{}", e.get())
// or
let mut e = Envelope{message: "hello"};
e.set("goodbye");
println!("{}", e.get())
```

#### TypeScript

```typescript
class Envelope<M> {
  message: M;

  constructor(newMessage: M) {
    this.message = newMessage;
  }

  get(): M {return this.message };
  set(newMessage: M) { this.message = newMessage; }
}

let e = new Envelope<string>("hello");
// or
let e2 = new Envelope("hello");
```

### Class generic over a subset of types

The class is generic over any type that conforms to some rules. In every case I've found, the only supported rule is equivalent to an `instanceof` check.

Some languages have multiple syntaxes for different sets of rules, others do not.

#### Kotlin

```kotlin
interface Sendable
interface HasReturnReceipt
class Message(val m: String): Sendable, HasReturnReceipt

class Envelope<M: Sendable>(private var message: M) {
    fun get(): M = message

    fun set(newMessage: M) {
        message = newMessage
    }
}

val e = Envelope<Message>(Message("Hello"))
// or
val e = Envelope(Message("Hello"))

// If there is more than one restriction, the restriction must move to a "where" clause:
class Envelope<M>(private var message: M) where M: Sendable, M: HasReturnReceipt {
    fun get(): M = message

    fun set(newMessage: M) {
        message = newMessage
    }
}

val e = Envelope<Message>(Message("Hello"))
// or
val e = Envelope(Message("Hello"))
```

#### Rust

```rust
trait Sendable {}
trait HasReturnReceipt {}

struct Message {
    m: String
}

impl Sendable for Message {}
impl HasReturnReceipt for Message {}

struct Envelope<M: Sendable> {
    message: M,
}

impl <T: Sendable> Envelope<T> {
    fn get(self) -> T {
        self.message
    }

    fn set(&mut self, new_message: T) {
        self.message = new_message
    }
}

let mut e = Envelope::<Message>{message: Message{m: "hello".to_string()}};
e.set(Message{m:"goodbye".to_string()});
println!("{}", e.get().m);
// or
let mut e = Envelope{message: Message{m: "hello".to_string()}};
e.set(Message{m:"goodbye".to_string()});
println!("{}", e.get().m);

// If there is more than one restriction, they are joined with +:
struct Envelope<M: Sendable + HasReturnReceipt> {
    message: M,
}

impl <T: Sendable + HasReturnReceipt> Envelope<T> {
    fn get(self) -> T {
        self.message
    }

    fn set(&mut self, new_message: T) {
        self.message = new_message
    }
}

// There is also an alternative "where" syntax that supports even more combinations.
// The cases that can only be done with "where" are unclear from the docs, but I believe
// are cases where another type that references the type has some rule. The docs example is
// impl<T> PrintInOption for T where Option<T>: Debug {}

struct Envelope<M> where M: Sendable + HasReturnReceipt {
    message: M,
}

impl <M> Envelope<M> where M: Sendable + HasReturnReceipt {
    fn get(self) -> M {
        self.message
    }

    fn set(&mut self, new_message: M) {
        self.message = new_message
    }
}
```

#### TypeScript

```typescript
interface Sendable {}
interface HasReturnReceipt {}

class Message implements Sendable, HasReturnReceipt {
  m: string;

  constructor(m: string) {
    this.m = m;
  }
}

// Note the keyword "extends", even though it's a interface.
class Envelope<M extends Sendable> {
  message: M;

  constructor(newMessage: M) {
    this.message = newMessage;
  }

  get(): M {return this.message };
  set(newMessage: M) { this.message = newMessage; }
}

let e = new Envelope<Message>(new Message("hello"));
// or
let e2 = new Envelope(new Message("hello"));

// Because Typescript supports union types, multiple restrictions are just a union type:
class Envelope<M extends Sendable|HasReturnReceipt> {
    message: M;

    constructor(newMessage: M) {
        this.message = newMessage;
    }

    get(): M {return this.message };
    set(newMessage: M) { this.message = newMessage; }
}
```

### Receiving a generic object

When a function/method wants to require a generic object as one if its parameters.

#### Kotlin

```kotlin
interface Maker<T> {
    fun make(id: Int): T
}

class Thing

class ThingMaker: Maker<Thing> {
    override fun make(id: Int): Thing {
        return Thing()
    }
}

// This function only accepts a Maker implementation that
// has been specialized to Thing.
fun wantsMakerOfThing(maker: Maker<Thing>) {}

// This function accepts only ThingMaker, which is contravariant
// with Maker<T>.
fun wantsThingMaker(maker: ThingMaker) {}

// This function accepts any Maker implementation that has been
// specialized. "Any" is the Kotlin top type.
fun wantsMaker(maker: Maker<Any>) {}

// This function is itself generic, so takes any Maker:
fun <T> wantsMaker(maker: Maker<T>) {}
```

#### Rust

```rust
trait Maker<T> {
    fn make(self, _id: i32) -> T;
}

struct Thing {}

struct ThingMaker {}

impl Maker<Thing> for ThingMaker {
    fn make(self, _id: i32) -> Thing {
        Thing{}
    }
}

// This function only accepts a Maker implementation that
// has been specialized to Thing.
fn wants_maker_of_thing(_maker: impl Maker<Thing>) {}

// This function accepts only ThingMaker.
fn wants_thing_maker(_maker: ThingMaker) {}

// This function accepts any Maker, as the function itself is still generic.
fn wants_thing_maker<T>(_maker: impl Maker<T>) {}
```

#### TypeScript

```typescript
interface Maker<T> {
    make(id: number): T;
}

class Thing {}

class ThingMaker implements Maker<Thing> {
    make(id: number): Thing {
        return new Thing();
    }
}

// This function only accepts a Maker implementation that
// has been specialized to Thing.
function wantsMakerOfThing(maker: Maker<Thing>) {}

// This function accepts only ThingMaker.
function wantsThingMaker(maker: ThingMaker) {}

// This function accepts any Maker, as the function itself is still generic.
function wantsMaker<T> (maker: Maker<T>) {}
```

### Covariant inheritance

Inheritance is only semi-supported by generics, because parameter and return types have conflicting requirements.  In the special case where your generic type is only used in return types, you can sometimes mark it to be covariant only.

#### Kotlin

```kotlin
interface Maker<out T> {
    fun make(id: Int): T
}

class Thing

// doer is of type "Maker<Any>".  Because Maker's type param
// is marked "out", that means if Foo extends Bar, then Maker<Foo>
// is a child of Maker<Bar> (aka, covariant).
fun wantsMakerProducer(maker: Maker<Thing>) {
    val doer: Maker<Any> = maker
}
```

#### Rust

```rust
// N/A
```

#### TypeScript

Typescript is an interesting duck.  It has two different compilation modes. In the default, all generics are treated as bivariant, so the co/contravariance issues noted above are simply ignored. With strictFunctionTypes enabled, it will check contravariance of parameters, but I find no evidence that it will check covariance of returns.

cf: https://www.typescriptlang.org/docs/handbook/release-notes/typescript-2-6.html#strict-function-types

From that changelog:

> By the way, note that whereas some languages (e.g. C# and Scala) require variance annotations (out/in or +/-), variance emerges naturally from the actual use of a type parameter within a generic type due to TypeScript’s structural type system.

However, other sources seem to suggest there are `in`/`out` keywords:

https://levelup.gitconnected.com/what-is-the-use-of-in-and-out-annotations-in-ts-generics-ba98c706e7f3

In testing, I was also unable to get the example above to trigger an error, even in strict mode.

In short, I am very confused by TypeScript's behavior here.  However, given that PHP doesn't do
structural typing at all, I don't think it is relevant for our purposes to investigate further.

### Contravariant inheritance

Inheritance is only semi-supported by generics, because parameter and return types have conflicting requirements.  In the special case where your generic type is only used in parameter types, you can sometimes mark it to be contravariant only.

```kotlin
open class Thing
open class Item: Thing()

interface Receiver<in T> {
    fun take(v: T)
}

class ThingReceiver: Receiver<Thing> {
    override fun take(v: Thing) {}
}

// taker is of type "Receiver<Item>".  Because Receiver's type param
// is marked "in", that means if Foo extends Bar, then Receiver<Bar>
// is a super-type of Receiver<Foo> (aka, contravariant).
fun receive(r: Receiver<Thing>) {
    val taker: Receiver<Item> = r
}
```

#### Rust

```rust
// N/A
```

## Possible PHP syntax

The syntax of our family of languages is remarkably similar for generics, so it would be wise to follow the same patterns where possible.  That makes both the syntax and semantics easier to learn.  In particular, I would note:

* The `<>` is an obvious syntax to use for generics.  Of major similar languages, only Go doesn't use that.
* Given PHP's support for union and intersection types, we probably do not need a `where` clause equivalent.  Type restrictions that complex are, most likely, not relevant for most PHP cases.
* We should follow Kotlin and Rust rather than TypeScript and always enforce co/contravariance.  The use of `in`/`out` seems pretty common.  Kotlin uses it, as does C# (not yet shown, will add at some point).
* For functions, we can put the `<>` before or after the name.  Both exist.  I think after is more common, and more sensible for PHP.
* When restricting a generic type, I'm tempted to use the `:` syntax of Rust and Kotlin, as it is shorter and easier.  However, those languages also use `:` for normal inheritance, making it parallel.  PHP uses the longer `extends` keyword, also used by Java and TypeScript, and those languages use `extends` for generic restrictions.  That suggests it would be more consistent, if more verbose, to use the full word.  I am still torn on this point.
* Type inference is very useful.  However, the code still works without it.  Whether we include it initially or not should be based on how hard it is to support in the narrow cases relevant to generics.

```php
function aGenericFunction<T extends Book>(T $b): T {}

// This version is invariant.
class Library<T extends Book|Magazine> {
    public function __construct(private T $b) {}

    public function get(): T {
        return $this->b;
    }

    public function set(T $b): void {
        $this->b = $b;
    }
}

// This version is covariant.
class Library<out T extends Book|Magazine> {
    public function get(): T {
        return new T();
    }
}

// This version is contravariant.
class Library<T extends Book|Magazine> {
     public function set(T $b): void {
        $temp = $b;
    }
}
```
