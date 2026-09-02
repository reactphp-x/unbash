#!/bin/bash
file="/etc/passwd"
dir="/tmp"
str="hello"
num=42
empty=""

[[ -f $file ]]
[[ -d $dir ]]
[[ -e $file ]]
[[ -r $file ]]
[[ -w $dir ]]
[[ -x /usr/bin/env ]]
[[ -s $file ]]
[[ -L /usr/bin/python ]]
[[ -b /dev/sda ]]
[[ -c /dev/null ]]
[[ -p /dev/stdin ]]
[[ -S /var/run/docker.sock ]]

[[ -z $empty ]]
[[ -n $str ]]
[[ $str == hello ]]
[[ $str != world ]]
[[ $str == h* ]]
[[ $str =~ ^[a-z]+$ ]]
[[ $str < world ]]
[[ $str > aaa ]]

[[ $num -eq 42 ]]
[[ $num -ne 0 ]]
[[ $num -lt 100 ]]
[[ $num -le 42 ]]
[[ $num -gt 0 ]]
[[ $num -ge 42 ]]

[[ -f $file && -r $file ]]
[[ -d $dir || -f $dir ]]
[[ ! -z $str ]]
[[ -f $file && -r $file && -s $file ]]
[[ -d $dir && ( -w $dir || -x $dir ) ]]
[[ ( -f $file || -d $file ) && -r $file ]]
[[ ! ( -z $str || -z $file ) ]]
[[ $str == hello && $num -eq 42 || $empty == "" ]]

[[ $file -nt $dir ]]
[[ $dir -ot $file ]]
[[ $file -ef $file ]]

[[ -v num ]]
[[ -v BASH_VERSION ]]

[[ $str =~ ^([a-z]+)([0-9]*)$ ]]
[[ $file =~ /etc/(.*) ]]
[[ $str =~ (hello|world) ]]

[ -f "$file" ]
[ -d "$dir" ]
[ "$str" = "hello" ]
[ "$num" -eq 42 ]
[ -f "$file" ] && [ -r "$file" ]
[ -z "$empty" ] || [ -n "$str" ]
[ ! -d "$file" ]

if [[ -f $file && -r $file ]]; then
  if [[ $str =~ ^[a-z]+$ ]]; then
    [[ $num -gt 0 ]] && echo positive
  fi
fi

while [[ $num -gt 0 ]]; do
  (( num-- ))
  [[ $((num % 2)) -eq 0 ]] && continue
done

# More complex test expression patterns
version="4.2.1"
[[ $version =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]
[[ ${BASH_REMATCH[1]} -ge 4 && ${BASH_REMATCH[2]} -ge 0 ]]

[[ $OSTYPE == linux-gnu* ]] || [[ $OSTYPE == darwin* ]]
[[ $TERM == xterm* || $TERM == screen* || $TERM == tmux* ]]
[[ $SHELL == */bash ]] && [[ ${BASH_VERSION%%.*} -ge 4 ]]

ip="192.168.1.100"
[[ $ip =~ ^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$ ]]

email="user@example.com"
[[ $email =~ ^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]]

uuid="550e8400-e29b-41d4-a716-446655440000"
[[ $uuid =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$ ]]

semver="2.1.0-rc.1+build.123"
[[ $semver =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[a-zA-Z0-9.]+)?(\+[a-zA-Z0-9.]+)?$ ]]

for f in /etc/*; do
  [[ -f $f && -r $f && ! -L $f ]] || continue
  [[ $(wc -l < "$f") -gt 100 ]] && echo "$f is large"
done

[[ -t 0 ]] && echo "stdin is terminal"
[[ -t 1 ]] && echo "stdout is terminal"
[[ -t 2 ]] && echo "stderr is terminal"

[[ $- == *i* ]] && echo "interactive"
[[ $0 == -* ]] && echo "login shell"
[[ ${FUNCNAME[0]:-main} != main ]] && echo "in function"

[[ -d $dir && -w $dir && ! -L $dir && -x $dir ]]
[[ $str == [Hh]ello ]]
[[ $str == ?ell* ]]
[[ $str == [!A-Z]* ]]
[[ $file == *.@(sh|bash|zsh) ]]

[ "$a" = "$b" ] && [ "$c" != "$d" ] || [ -z "$e" ]
[ \( -f "$f1" -o -f "$f2" \) -a -r "$f3" ]
[ "$x" -gt 0 ] && [ "$x" -lt 100 ] && [ "$x" -ne 50 ]
test -f "$file" && test -s "$file"
test "$str" = "hello" -o "$str" = "world"
