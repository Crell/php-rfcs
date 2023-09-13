# Generics use cases in various languages

## General design model

### Kotlin

* Generic classes and functions.
* Type inference to make it easier.
* Runtime erased.

### Rust

* Generic types and functions
* Monomorphized (compile time generated type-specific implementations)

### C#

* Generic classes and functions.
* Reified generics (runtime genericity)
* Supported by reflection
* Different typed versions of a class have separate static properties.

### Java

* Generic classes.
* Type inference.
* Cannot be generic over primitive type, only boxed (eg, `Integer` object on `int`)
* Runtime erased.
* Full compile time type safety not achieved.

### Typescript

* Runtime erased (lost when compiling to Javascript)

## Examples

### Functions

```kotlin
fun <T> takeAction(target: T): T {
    return target
}

val aDouble = 3.14
val result = takeAction<Double>(aDouble)
// or
val result = takeAction(aDouble)
```

### Class generic over all types

The class is generic over any type supported by the language.

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

### Class generic over a subset of types

The class is generic over any type that conforms to some rules, eg, `instancof`.

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

### Receiving a generic object

When a function/method wants to require a generic object as one if its parameters.

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
fun wantsThingMaker(maker: Maker<Thing>) {}

// This function accepts only ThingMaker, which is contravariant
// with Maker<T>.
fun wantsThingMaker(maker: ThingMaker) {}

// This function accepts any Maker implementation that has been
// specialized. "Any" is the Kotlin top type.
fun wantsMaker(maker: Maker<Any>) {}
```

### Covariant inheritance

Inheritance is only semi-supported by generics, because parameter and return types have conflicting requirements.  In the special case where your generic type is only used in return types, you can sometimes mark it to be covariant only.

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
