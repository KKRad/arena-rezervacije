#!/bin/zsh
# Upis lozinke PROBNE baze u lokalnu datoteku (unos se ne prikazuje; datoteka nije u gitu).
f="$(dirname "$0")/../storage/proba-db-pass"
read -rs "P?Lozinka probne baze (ista kao u hPanelu): "
echo
[[ -z "$P" ]] && { echo "Prazno — ništa nije upisano."; exit 1; }
printf '%s' "$P" > "$f" && chmod 600 "$f"
unset P
echo "OK — lozinka je upisana. Javite u chatu: Baza je gotova."
