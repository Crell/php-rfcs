# Possible Pipe Patterns

|                      | Equivalent             | Classic Pipe                  | Elixir                        | Classic with PFA          | Elixir with PFA             | Optimizable?    |
|----------------------|------------------------|-------------------------------|-------------------------------|---------------------------|-----------------------------|-----------------|
| FCC                  | `b($a)`                | `$a \|> b(...)`               | `$a \|> b(...)`               | `$a \|> b(...)`           | `$a \|> b(...)`             | Y               |
| Closure var          | `$b($a)`               | `$a \> $b`                    | `$a \> $b`                    | `$a \> $b`                | `$a \> $b`                  | Not needed      |
| Array style          | `[$b, 'c']($a)`        | `$a \|> [$b, 'c']`            | `$a \|> [$b, 'c']`            | `$a \|> [$b, 'c']`        | `$a \|> [$b, 'c']`          | N               |
| Higher order fn      | `b('c')($a)`           | `$a \|> b('c')`               | `$a \|> b('c')()`             | `$a \|> b('c')`           | `$a \|> b('c')()`           | N               |
| Partial in first pos | `b($a, 'c')`           | `$a \|> fn($x) => b($x, 'c')` | `$a \|> b('c')`               | `$a \|> b(?, 'c')`        | `$a \|> b('c')`             | Y (Elixir only) |
| Partial in other pos | `b('c', $a)`           | `$a \|> fn($x) => b('c', $x)` | `$a \|> fn($x) => b('c', $x)` | `$a \|> b('c', ?)`        | `$a \|> b('c', ?)`          | Y (PFA only)    |
| Arbitrary expr       | `($obj->foo()->b)($a)` | `$a \|> ($obj->foo()->b)`     | `$a \|> ($obj->foo()->b)()`   | `$a \|> ($obj->foo()->b)` | `$a \|> ($obj->foo()->b)()` | N               |

