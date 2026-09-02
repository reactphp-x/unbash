#!/bin/bash
x=10
y=25
z=3

echo $((x + y))
echo $((x - y))
echo $((x * y))
echo $((y / x))
echo $((y % x))
echo $((2 ** 10))
echo $((x << 2))
echo $((y >> 1))
echo $((x & y))
echo $((x | y))
echo $((x ^ y))
echo $((~x))
echo $((x > y ? x : y))
echo $((x == 10 && y == 25))
echo $((x != y || z == 3))
echo $(((x + y) * (z - 1)))
echo $((x > 0 ? (y > 0 ? x + y : x) : y))

(( x++ ))
(( y-- ))
(( x += 5 ))
(( y -= 3 ))
(( z *= 10 ))
(( x /= 2 ))
(( y %= 7 ))
(( x &= 0xFF ))
(( y |= 0x10 ))
(( z ^= 0x5 ))
(( x <<= 1 ))
(( y >>= 2 ))
(( result = x + y * z ))
(( flag = (x > y) && (z != 0) ))
(( val = x > y ? x - y : y - x ))
(( nested = (a + b) * (c + d) - (e / f) ))

for (( i = 0; i < 20; i++ )); do
  echo $((i * i))
  (( sum += i ))
  (( i % 2 == 0 )) && (( evens++ ))
done

for (( a = 100; a > 0; a /= 2 )); do
  echo $((a + 1))
done

for (( i = 0, j = 10; i < j; i++, j-- )); do
  echo $((i + j))
done

n=10
echo $((n * (n + 1) / 2))
echo $((n <= 1 ? n : n * $((n - 1))))
echo $(( (1 << n) - 1 ))

base=16
echo $((0xFF))
echo $((0777))
echo $((2#10101010))
echo $((base#A))

echo $((x == 0 ? 1 : (x > 0 ? x : -x)))
echo $(( (x + y + z) / 3 ))
echo $(( x * 100 / (x + y + z) ))

# Additional arithmetic patterns
(( width = 80 ))
(( height = 24 ))
(( area = width * height ))
(( perimeter = 2 * (width + height) ))
(( diagonal_sq = width * width + height * height ))

(( celsius = (fahrenheit - 32) * 5 / 9 ))
(( kelvin = celsius + 273 ))

for (( row = 0; row < 8; row++ )); do
  for (( col = 0; col < 8; col++ )); do
    (( idx = row * 8 + col ))
    (( bit = (mask >> idx) & 1 ))
    echo $((bit))
  done
done

(( factorial = 1 ))
for (( k = 1; k <= 12; k++ )); do
  (( factorial *= k ))
done
echo $((factorial))

(( gcd_a = 48, gcd_b = 36 ))
while (( gcd_b != 0 )); do
  (( tmp = gcd_b ))
  (( gcd_b = gcd_a % gcd_b ))
  (( gcd_a = tmp ))
done
echo $((gcd_a))

(( fib_a = 0, fib_b = 1 ))
for (( fi = 0; fi < 20; fi++ )); do
  echo $((fib_a))
  (( tmp = fib_a + fib_b ))
  (( fib_a = fib_b ))
  (( fib_b = tmp ))
done

echo $(( 0xDEAD & 0xFF ))
echo $(( 0xBEEF | 0xF000 ))
echo $(( 0xCAFE ^ 0xBABE ))
echo $(( ~0xFFFF & 0xFFFFFFFF ))
echo $(( 1 << 16 | 1 << 8 | 1 ))

(( rgb = (255 << 16) | (128 << 8) | 64 ))
(( r = (rgb >> 16) & 0xFF ))
(( g = (rgb >> 8) & 0xFF ))
(( b = rgb & 0xFF ))

(( is_power_of_2 = (n > 0) && ((n & (n - 1)) == 0) ))
(( next_pow2 = 1 ))
while (( next_pow2 < n )); do
  (( next_pow2 <<= 1 ))
done

(( min = x < y ? x : y ))
(( max = x > y ? x : y ))
(( clamp = val < lo ? lo : (val > hi ? hi : val) ))
(( abs = x < 0 ? -x : x ))
(( sign = x > 0 ? 1 : (x < 0 ? -1 : 0) ))
