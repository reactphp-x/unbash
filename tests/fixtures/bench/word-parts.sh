#!/bin/bash
msg="hello world"
greeting='welcome back'
echo "$msg" '$literal' "$greeting"
name="user"
full="prefix-$name-suffix"
mixed=un"quo"ted'mix'"end"
path="/home/$name/.config"
escaped="tab\there\nnewline"
ansi=$'line1\nline2\ttab'
locale=$"translatable string"
concat="first""second"'third'
nested="outer $(echo "inner $name value") end"
back="old `echo style $name` compat"
empty=""
single=''
dollar="price: \$5"
complex="a'b\"c\$d\`e"
for file in "one two" 'three four' five"six"seven; do
  echo "Processing $file"
  dest="/output/$file.bak"
  cp "$file" "$dest"
  log="$log copied:$file"
done
declare -a items=("first item" "second item" 'third item' "fourth $name")
for i in "${items[@]}"; do
  printf '%s\n' "$i"
done
case "$msg" in
  "hello"*) echo "starts with hello" ;;
  'exact') echo 'matched' ;;
  pre"fi"x*) echo "has prefix" ;;
esac
func() {
  local result="$1-processed"
  local tag='['$2']'
  echo "$result $tag"
}
func "input" "label"
url="https://example.com/path?q=$name&t=1"
header="Content-Type: application/json"
body='{"key": "value", "name": "'$name'"}'
curl -H "$header" -d "$body" "$url"
read -r line <<< "tab\tsep\tfields"
IFS=',' read -ra parts <<< "a,b,c,d"
for p in "${parts[@]}"; do echo "$p"; done
template="Hello, NAME. Welcome to PLACE."
output="${template//NAME/$name}"
output="${output//PLACE/home}"
echo "$output"
printf "%-20s %s\n" "$name" "$msg"
printf '%q\n' "special chars: \$\`\""
log_prefix="[$(date +%Y-%m-%d)]"
echo "$log_prefix $msg"
multiline="line 1
line 2
line 3"
echo "$multiline" | while read -r l; do echo ">> $l"; done

# More word-part patterns: tilde, globs, nested expansions
home=~/"documents"
other=~root/".bashrc"
echo "$HOME"'/bin:'"$PATH"
echo "result: $(echo "deep $(echo "deeper $name")")"
echo "back: `echo \`echo $name\``"
triple="$a"'$b'"$c"
for arg in --flag="$val" --name='literal' --path="$HOME"/.config; do
  echo "$arg"
done
echo "ternary: $((x > 0 ? x : -x))"
echo "nested arith: $((2 * $((3 + 4))))"
msg_tmpl='Hello, '"$name"'! You have '"$count"' items.'
echo "$msg_tmpl"
echo "escaped: \"\$var\" and '\$literal'"
var="has spaces and 'quotes' and \"doubles\""
echo "$var"
tag="<div class=\"$cls\" id='$id'>$content</div>"
echo "$tag"
json='{"arr":['
for item in "${items[@]}"; do
  json+="\"$item\","
done
json+=']}'
echo "$json"
query="SELECT * FROM \"$table\" WHERE name = '$name'"
echo "$query"
heredoc_word=$(cat <<EOF
line with $name and $(hostname)
EOF
)
echo "captured: $heredoc_word"
printf "%s=%s\n" "name" "$name" "path" "$path" "msg" "$msg"
echo "glob:"' *.txt'" and brace:"" {a,b}"
re="^[a-z]+$"
[[ $name =~ $re ]] && echo "$name matches $re"
prompt="$USER@$(hostname):$PWD\$ "
echo "$prompt"
declare -A conf=(["host"]="localhost" ["port"]="8080" ["db"]="main")
for key in "${!conf[@]}"; do
  echo "$key = ${conf[$key]}"
done
csv="$name,$path,$msg"
IFS=',' read -ra fields <<< "$csv"
for f in "${fields[@]}"; do echo "field: $f"; done
echo "done with word parts"
