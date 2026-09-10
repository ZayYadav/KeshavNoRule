from pathlib import Path


def replace_once(path: str, old: str, new: str, label: str) -> None:
    p = Path(path)
    text = p.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected 1 marker, found {count}')
    p.write_text(text.replace(old, new, 1), encoding='utf-8')

replace_once(
    'teamdark-panel/app/ReferralManager.php',
    """        $pendingQ = $pdo->prepare(
            \"SELECT COALESCE(SUM(grant_balance),0)
             FROM referral_invites
             WHERE created_by=? AND status='pending'\"
        );
""",
    """        $pendingQ = $pdo->prepare(
            \"SELECT COALESCE(SUM(grant_balance),0)
             FROM referral_invites
             WHERE created_by=? AND status='pending'
               AND (expires_at IS NULL OR expires_at>NOW())\"
        );
""",
    'ReferralManager pending quota expiry filter',
)

replace_once(
    'teamdark-panel/public/index.php',
    """                $pendingQ = $pdo->prepare(
                    \"SELECT COALESCE(SUM(grant_balance),0)
                     FROM referral_invites
                     WHERE created_by=? AND status='pending'\"
                );
""",
    """                $pendingQ = $pdo->prepare(
                    \"SELECT COALESCE(SUM(grant_balance),0)
                     FROM referral_invites
                     WHERE created_by=? AND status='pending'
                       AND (expires_at IS NULL OR expires_at>NOW())\"
                );
""",
    'manual balance pending quota expiry filter',
)

print('referral quota expiry filters applied')
