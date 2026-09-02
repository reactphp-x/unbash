#!/bin/bash
name="world"
count=5
items=(a b c)

cat <<EOF
Hello $name!
You have $count items.
Your home is $HOME.
Current dir: $(pwd)
Array: ${items[@]}
Arithmetic: $((count * 2))
EOF

cat <<'LITERAL'
No $expansion here.
Not even $(commands).
Or ${braces}.
Just literal \text.
LITERAL

cat <<-INDENTED
	Hello $name
	Indented with $count tabs
	Command: $(echo stripped)
INDENTED

read -r -d '' html <<EOF
<html>
<head><title>$name's page</title></head>
<body>
  <h1>Hello $name</h1>
  <p>Items: ${#items[@]}</p>
  <p>Date: $(date +%Y-%m-%d)</p>
</body>
</html>
EOF

query=$(cat <<EOF
SELECT u.name, u.email
FROM users u
WHERE u.active = 1
  AND u.name LIKE '%$name%'
ORDER BY u.created_at
LIMIT $count
EOF
)

config=$(cat <<'EOF'
server {
    listen 80;
    server_name example.com;
    location / {
        proxy_pass http://localhost:3000;
    }
}
EOF
)

cat <<EOF > /dev/null
export PATH="$HOME/bin:$PATH"
export NAME="$name"
export COUNT="$count"
alias ll='ls -la'
EOF

cat <<-EOF
	{
	  "name": "$name",
	  "count": $count,
	  "items": [$(printf '"%s",' "${items[@]}" | sed 's/,$//')],
	  "nested": {
	    "home": "$HOME",
	    "pwd": "$(pwd)"
	  }
	}
EOF

while read -r line; do
  echo ">> $line"
done <<EOF
line 1 with $name
line 2 with $count
line 3 with $(echo computed)
EOF

sed 's/foo/bar/' <<'EOF'
no $expansion
literal text only
another line
EOF

mysql <<EOF
INSERT INTO logs (msg, ts) VALUES ('$name logged in', NOW());
SELECT * FROM users WHERE name = '$name' LIMIT $count;
EOF

# Multiple heredocs on one line
diff <(cat <<EOF1
first $name
with $count
EOF1
) <(cat <<EOF2
second $name
no $count here
EOF2
)

# Heredoc with backtick expansion
cat <<EOF
backtick: `echo $name`
dollar: $(echo $name)
mixed: `echo ${name}` and $(echo ${count})
EOF

# Nested command substitution in heredoc
result=$(cat <<EOF
outer $name
inner $(echo "deep $(echo $count)")
arith $((count * count))
param ${name:-default} ${name:+exists}
EOF
)

# Heredoc in a function
generate_script() {
  cat <<-EOF
	#!/bin/bash
	echo "Generated for $name"
	echo "Count: $count"
	for i in $(seq 1 $count); do
	  echo "Item \$i"
	done
	EOF
}

# Heredoc with special chars in body
cat <<EOF
Escapes: \$literal \`not-expanded\` \\backslash
But these expand: $name ${count} $(echo yes)
Tab:	here
Newline in string: "multi
line"
Single quotes: '$name' (still expands)
EOF

# Here-string variants
cat <<< "simple $name"
cat <<< 'literal $name'
cat <<< $name
read -r x y z <<< "split $name into ${count} parts"

# Heredoc as function argument
process() { cat; }
process <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
main() {
  echo "no expansion in quoted heredoc"
  local x=$1
  return 0
}
main "$@"
SCRIPT

# Heredoc piped
cat <<EOF | grep -c name
line with $name
line without
another $name line
plain line
EOF

# Indented heredoc with mixed content
if true; then
  cat <<-CONF
	[section]
	key = $name
	count = $count
	path = ${HOME}/.config
	cmd = $(hostname)
	CONF
fi
