#!/bin/bash
var="hello world"
name="username"
path="/home/user/documents/file.txt"
url="https://example.com/api/v2/resource"
version="1.2.3-beta.4"

echo ${var:-default_value}
echo ${unset:-fallback}
echo ${var:=assigned}
echo ${var:+alternate}
echo ${var:?error: var is unset}
echo ${#var}
echo ${#path}
echo ${#url}

echo ${path%/*}
echo ${path%%/*}
echo ${path#*/}
echo ${path##*/}
echo ${url%/*}
echo ${url%%/*}
echo ${url#*/}
echo ${url##*/}

echo ${version/beta/rc}
echo ${version//./,}
echo ${path/home/opt}
echo ${url///|}

echo ${var:0:5}
echo ${var:6}
echo ${path:1:4}
echo ${version:0:3}
echo ${url:8:11}

echo ${var^}
echo ${var^^}
echo ${name,}
echo ${name,,}

echo ${!BASH*}
echo ${!USER*}
echo ${!HOME*}

echo ${var@Q}
echo ${var@E}
echo ${var@P}
echo ${var@A}

arr=(alpha bravo charlie delta echo foxtrot)
echo ${arr[@]}
echo ${arr[0]}
echo ${arr[@]:2:3}
echo ${#arr[@]}
echo ${arr[@]^^}
echo ${arr[@]/a/A}
echo ${arr[@]%o*}

declare -A map=([name]=alice [age]=30 [city]=paris)
echo ${map[name]}
echo ${map[@]}
echo ${!map[@]}
echo ${#map[@]}

base=${path##*/}
ext=${base##*.}
stem=${base%.*}
dir=${path%/*}
parent=${dir##*/}
echo "file=$base ext=$ext stem=$stem dir=$dir parent=$parent"

major=${version%%.*}
rest=${version#*.}
minor=${rest%%.*}
rest2=${rest#*.}
patch=${rest2%%-*}
pre=${version##*-}
echo "v$major.$minor.$patch pre=$pre"

proto=${url%%://*}
hostpath=${url#*://}
host=${hostpath%%/*}
rpath=${hostpath#*/}
echo "proto=$proto host=$host path=$rpath"

input="  hello world  "
trimmed=${input#"  "}
trimmed=${trimmed%"  "}
echo "[$trimmed]"

text="CamelCaseIdentifier"
lower=${text,,}
upper=${text^^}
first_lower=${text,}
echo "$lower $upper $first_lower"

# Edge cases from tree-sitter corpus
echo ${parameter-default}
echo ${parameter:-default}
echo ${parameter=default}
echo ${parameter:=default}
echo ${parameter+alt_value}
echo ${parameter:+alt_value}
echo ${parameter?err_msg}
echo ${parameter:?err_msg}

A="${B[0]# }"
C="${D/#* -E /}"
F="${G%% *}"
H="${I#*;}"
J="${K##*;}"
L="${M%;*}"
N="${O%%;*}"
P="${Q%|*}"
R="${S%()}"
T="${U%(}"
V="${W%)}"
X="${Y%<}"
Z="${A#*<B>}"
C="${D%</E>*}"
F="${#!}"
G=${H,,[I]}
J=${K^^[L]}
L="${M/'N'*/O}"

${completions[*]}
echo ${p_key#*=}
echo ${abc:- }
echo ${B[0]# }
echo ${to_enables[0]##*/}
echo "${kw}? ( ${cond:+${cond}? (} ${baseuri}-${ver}-${kw}.${suff} ${cond:+) })"

echo ${parameter: -1}
echo ${parameter:(-1)}
echo "${PN::-1}"
${comp[@]:start:end*2-start}
A="${A:-$B/c}"
A="${b=$c/$d}"
MY_PV="${PV/_pre/$'\x7e'pre}"

A=${B//:;;/$'\n'}
C=${D/;\ *;|}
MOFILES=${LINGUAS// /.po }.po
pyc=${pyc//*\/}
${pv/\.}
${allarchives// /\\|}

echo ${LIB_DEPEND//\[static-libs(+)]}
${ALL_LLVM_TARGETS[@]/%/(-)?}
filterdiff -p1 ${paths[@]/#/-i }
${cflags//-O? /$(get-flag O) }
curf="${f%'-roff2html'*}.html"
reff="${f/'-roff2html'*/'-ref'}.html"

${id}${2+ ${2}}
${BRANDING_GCC_PKGVERSION/(/(Gentoo ${PVR}${extvers}, }
some-command ${foo:+--arg <(printf '%s\n' "$foo")}

echo ${!#}
echo ${!##}
echo ${!## }
echo ${!##/}
