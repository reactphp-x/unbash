#!/bin/bash
x=hello
y="hello world"
z='literal $value'
n=42
empty=
path=/usr/local/bin

x+=_more
y+=" appended"
n+=5
path+=:/usr/bin

arr=(alpha bravo charlie delta echo)
arr+=(foxtrot golf)
arr[5]=hotel
arr[10]=kilo

declare -a list=(one two three four five)
declare -A map=([name]=alice [age]=30 [city]=paris [lang]=en)
declare -i num=42
declare -r constant="immutable"
declare -l lower="HELLO"
declare -u upper="hello"
declare -x exported="visible"

local_func() {
  local a=1
  local b="two"
  local -i c=3
  local -a d=(x y z)
  local -A e=([k]=v)
  local result
  result="$a $b $c"
}

export PATH="/usr/bin:$PATH"
export HOME LANG LC_ALL
export -n unexported

readonly PI=3.14159
readonly E=2.71828
readonly -a CONSTANTS=(1 2 3)

typeset -i counter=0
typeset -a buffer=()

x="value with spaces"
y=$(echo computed)
z=$(cat /etc/hostname)
arr=($(seq 1 10))
map[key]="new value"
map[key2]=${map[key]}_derived

IFS=, read -ra parts <<< "a,b,c,d,e"
while IFS=: read -r user _ uid gid _ home shell; do
  echo "$user $uid $home"
done < /etc/passwd

a=1 b=2 c=3 command arg1 arg2
PATH=/custom:$PATH VAR=val exec program
LC_ALL=C sort file.txt

for (( i=0; i<5; i++ )); do
  var_$i="value_$i"
  eval "dynamic_$i=content_$i"
done

read -r first second rest <<< "alpha bravo charlie delta"
printf -v formatted "%-10s %5d" "$x" "$n"

# Additional assignment patterns
declare -a matrix=()
for (( r=0; r<3; r++ )); do
  for (( c=0; c<3; c++ )); do
    matrix[$((r * 3 + c))]=$((r + c))
  done
done

declare -A config=(
  [db_host]="localhost"
  [db_port]="5432"
  [db_name]="myapp"
  [db_user]="admin"
  [db_pass]="secret"
  [cache_ttl]="3600"
  [log_level]="info"
  [max_conns]="100"
)

defaults=("--verbose" "--color=auto" "--timeout=30")
flags=("${defaults[@]}" "--retry=3")

builddir=${BUILDDIR:-build}
prefix=${PREFIX:-/usr/local}
cc=${CC:-gcc}
cflags=${CFLAGS:-"-O2 -Wall"}

src_files=(src/*.c)
obj_files=("${src_files[@]/.c/.o}")
dep_files=("${src_files[@]/.c/.d}")

OLDIFS=$IFS
IFS=$'\n'
lines=($(cat /etc/shells 2>/dev/null))
IFS=$OLDIFS

total=0
for val in "${lines[@]}"; do
  count=${#val}
  total=$((total + count))
done

declare -gA registry=()
register() {
  local key=$1
  local val=$2
  registry[$key]=$val
}
register name "test"
register version "1.0"
register author "user"

TMP=$(mktemp)
LOG=${TMP%.tmp}.log
PID=$$
SCRIPT=${BASH_SOURCE[0]}
DIR=$(cd "$(dirname "$SCRIPT")" && pwd)

ENV_FILE=${ENV_FILE:-.env}
DEBUG=${DEBUG:-0}
VERBOSE=${VERBOSE:-$DEBUG}
QUIET=${QUIET:-0}

mapfile -t conf_lines < /dev/null
readarray -t csv_fields <<< "a,b,c"

HTTP_HOST=0.0.0.0 HTTP_PORT=8080 NODE_ENV=production node server.js
PGPASSWORD=secret psql -U admin -d mydb -c "SELECT 1"
TERM=dumb COLUMNS=80 LINES=24 less file.txt
