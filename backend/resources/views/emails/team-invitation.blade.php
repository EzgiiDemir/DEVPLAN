<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color: #1a1a1a; line-height: 1.5;">
    <p>{{ $inviterName }} invited you to join <strong>{{ $teamName }}</strong> on DevPlan as a {{ $role }}.</p>
    <p>
        <a href="{{ $acceptUrl }}" style="display: inline-block; background: #1a1a1a; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 6px;">
            Accept invitation
        </a>
    </p>
    <p style="color: #666; font-size: 13px;">If the button doesn't work, copy this link: {{ $acceptUrl }}</p>
</body>
</html>
