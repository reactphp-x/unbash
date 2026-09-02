#!/bin/bash

# Arithmetic commands (( ))
(( x = 1 + 2 ))
(( y = x * 3 ))
(( x > y )) && echo "x wins"
(( total += x + y ))
(( flag = (x > 0) && (y > 0) ))
if (( x > 0 )); then echo positive; fi
while (( count-- > 0 )); do echo $count; done
(( a = b + c - d * e / f ))
(( p = (q > r) ? q : r ))
(( bits = (1 << 8) - 1 ))
(( mask &= 0xFF ))
(( result = a % b + c % d ))
(( ok = !failed ))
for (( i = 0; i < 10; i++ )); do
  (( sum += i * i ))
  (( i % 3 == 0 )) && (( threes++ ))
done
(( x = y > 0 ? y * 2 : -y ))
(( compound = (a + b) * (c - d) + (e << f) ))
(( status = (ret == 0) && (errno == 0) ))

# Test commands [[ ]]
[[ $a == $b ]]
[[ -f $file && -r $file ]]
[[ $str =~ ^[0-9]+$ ]]
if [[ $x -gt 0 ]]; then echo positive; fi
while [[ $n -ne 0 ]]; do (( n-- )); done
[[ $a == b* ]] && echo matches
[[ -d $dir && -w $dir ]]
[[ $s1 < $s2 ]]
[[ -z $empty || -n $fallback ]]
[[ ! -e $path ]]
[[ $val =~ ^-?[0-9]+(\.[0-9]+)?$ ]]
[[ $OSTYPE == darwin* ]] && echo mac
[[ $ver =~ ^([0-9]+)\.([0-9]+) ]]
[[ ${BASH_REMATCH[1]} -ge 4 ]]
[[ -f $conf ]] && source "$conf"
[[ $mode == "debug" && -v VERBOSE ]]
[[ ( -f $a || -f $b ) && -r $c ]]
[[ $line =~ ^[[:space:]]*# ]] && continue
[[ $input == +(digit) ]] || echo "not numeric"
[[ -S /var/run/app.sock && -w /var/run/app.sock ]]
[[ $EUID -ne 0 ]] && echo "not root"

# Process substitution <() >()
diff <(sort file1) <(sort file2)
comm -3 <(sort list1) <(sort list2)
paste <(cut -f1 data) <(cut -f2 data)
tee >(grep error > errors.log) >(wc -l > count.txt) > /dev/null
cat <(echo header) data.csv <(echo footer)
while read -r line; do echo "$line"; done < <(find . -name "*.sh")
exec > >(tee -a logfile)
diff <(ls dir1) <(ls dir2)
join <(sort -k1,1 file1) <(sort -k1,1 file2)
diff <(curl -s url1) <(curl -s url2)
paste -d, <(cut -d: -f1 /etc/passwd) <(cut -d: -f3 /etc/passwd)
tee >(gzip > backup.gz) >(md5sum > checksum.txt) > /dev/null
while read -r pid cmd; do
  echo "$pid: $cmd"
done < <(ps -eo pid,comm --no-headers)
sort -u <(cat list1) <(cat list2) <(cat list3)
grep -f <(awk '{print $1}' patterns) data.txt

# Extended globs
echo !(*.txt)
echo !(*.log|*.tmp)
echo @(foo|bar|baz)
echo ?(prefix)_file
echo +(digit)
echo *(any)thing
ls ?(*.ts|*.js)
rm !(keep.txt)
cp @(a|b|c).txt dest/
case $file in +(*.sh|*.bash)) echo script ;; esac
mv !(*.keep|*.save) archive/
ls @(src|lib|bin)/*.js
echo ?(pre)main?(suf)
for f in !(*.o|*.a); do strip "$f"; done
ls +(0|1|2|3|4|5|6|7|8|9).txt
case $ext in @(jpg|jpeg|png|gif|webp)) echo image ;; esac
rm -f !(Makefile|*.h|*.c)

# Brace expansion
echo {a,b,c}.txt
echo {1..10}
echo {01..100..5}
echo {a..z}
echo {z..a..-2}
echo file{1,2,3}.{txt,log}
mkdir -p dir/{src,test,bench}/{lib,util}
echo {01..12}/{01..31}
cp file.txt{,.bak}
echo pre{a,b}mid{c,d}suf
mkdir -p project/{src/{main,test}/{java,resources},docs,build}
echo {A..Z}{0..9}
mv report.{csv,csv.bak}
touch log.{mon,tue,wed,thu,fri}
echo {0..255..16}
chmod 644 *.{html,css,js}
tar czf archive.tar.gz dir/{a,b,c}.conf
echo {red,green,blue}-{light,dark}
for f in test_{01..20}.txt; do touch "$f"; done
ln -s ../lib/lib{foo,bar,baz}.so .
echo {north,south}{east,west}
diff file.{old,new}
