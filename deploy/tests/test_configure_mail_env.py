import importlib.util
import unittest
from pathlib import Path


spec = importlib.util.spec_from_file_location(
    'configure_mail_env', Path(__file__).resolve().parents[1] / 'configure-mail-env.py'
)
mail_env = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mail_env)


class ConfigureMailEnvTest(unittest.TestCase):
    KEY = 'xkeysib-' + 'a' * 48

    def test_owner_approved_both_email_flags_on_with_installed_key(self):
        self.assertTrue(mail_env.EMAIL_FLAGS)
        original = f'BREVO_API_KEY="{self.KEY}"\nAPP_KEY=unchanged\n'
        updated, installed = mail_env.build_env(original, flags=mail_env.EMAIL_FLAGS)
        self.assertTrue(installed)
        self.assertIn('AUTH_EMAIL_VERIFICATION_ENABLED=true\n', updated)
        self.assertIn('ALERT_EMAIL_DIGEST_ENABLED=true\n', updated)

    def test_missing_key_keeps_both_features_off_and_retires_bird(self):
        original = 'MAIL_PASSWORD="bk_eu1_old"\nMAIL_HOST=eu1.smtp.bird.com\nAPP_KEY=unchanged\n'
        updated, installed = mail_env.build_env(original, flags=True)
        self.assertFalse(installed)
        self.assertIn('MAIL_MAILER=log\n', updated)
        self.assertIn('AUTH_EMAIL_VERIFICATION_ENABLED=false\n', updated)
        self.assertIn('ALERT_EMAIL_DIGEST_ENABLED=false\n', updated)
        self.assertIn('APP_KEY=unchanged\n', updated)
        self.assertNotIn('bk_eu1_', updated)
        self.assertNotIn('eu1.smtp.bird.com', updated)

    def test_owner_key_is_preserved_on_later_deploys(self):
        original = f'BREVO_API_KEY="{self.KEY}"\nAPP_KEY=unchanged\n'
        updated, installed = mail_env.build_env(original, flags=True)
        self.assertTrue(installed)
        self.assertEqual(1, updated.count('BREVO_API_KEY='))
        self.assertIn('MAIL_MAILER=brevo\n', updated)
        self.assertIn('AUTH_EMAIL_VERIFICATION_ENABLED=true\n', updated)

    def test_bad_key_is_rejected(self):
        with self.assertRaises(ValueError):
            mail_env.read_key(b'bk_eu1_not-a-brevo-key')
        self.assertEqual(self.KEY, mail_env.read_key(self.KEY.encode()))


if __name__ == '__main__':
    unittest.main()
