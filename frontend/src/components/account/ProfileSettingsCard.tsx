"use client";

import { useId, useRef, useState, type ChangeEvent, type FormEvent } from "react";
import { removeMyAvatar, updateMyAvatar, updateMyProfile } from "@/lib/auth";
import { validateEmail, validateName, validatePassword, validatePhone } from "@/lib/validation";
import { ApiError } from "@/lib/api";
import { EyeIcon, EyeOffIcon } from "@/components/icons";
import { Avatar } from "@/components/account/Avatar";
import type { AuthUser } from "@/types/auth";

const AVATAR_MAX_BYTES = 2 * 1024 * 1024;
const AVATAR_ACCEPTED_TYPES = ["image/jpeg", "image/jpg", "image/png", "image/webp"];

const inputClass =
  "w-full rounded-md border border-neutral-200 px-3 py-2 text-sm text-neutral-800 focus:border-red-500 focus:outline-none focus:ring-1 focus:ring-red-500/20";
const labelClass = "mb-1 block text-xs font-semibold text-neutral-500";
const errorClass = "mt-1 text-xs text-red-600";

/** Self-service account settings — shared by the customer and admin account pages. */
export function ProfileSettingsCard({ user, onUpdated }: { user: AuthUser; onUpdated: () => Promise<void> }) {
  const idPrefix = useId();
  const nameId = `${idPrefix}-name`;
  const emailId = `${idPrefix}-email`;
  const phoneId = `${idPrefix}-phone`;
  const currentPasswordId = `${idPrefix}-current-password`;
  const newPasswordId = `${idPrefix}-new-password`;
  const confirmPasswordId = `${idPrefix}-confirm-password`;

  const fileInputRef = useRef<HTMLInputElement>(null);
  const [avatarUrl, setAvatarUrl] = useState(user.avatar_url);
  const [avatarError, setAvatarError] = useState<string | null>(null);
  const [avatarBusy, setAvatarBusy] = useState(false);

  async function handleAvatarChange(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    event.target.value = ""; // allow re-selecting the same file later
    if (!file) return;

    setAvatarError(null);
    if (!AVATAR_ACCEPTED_TYPES.includes(file.type)) {
      setAvatarError("Please choose a JPG, PNG, or WEBP image.");
      return;
    }
    if (file.size > AVATAR_MAX_BYTES) {
      setAvatarError("Image must be 2MB or smaller.");
      return;
    }

    setAvatarBusy(true);
    try {
      const updated = await updateMyAvatar(file);
      setAvatarUrl(updated.avatar_url);
      await onUpdated();
    } catch (err) {
      setAvatarError(err instanceof ApiError ? err.message : "Could not upload that photo.");
    } finally {
      setAvatarBusy(false);
    }
  }

  async function handleAvatarRemove() {
    setAvatarError(null);
    setAvatarBusy(true);
    try {
      const updated = await removeMyAvatar();
      setAvatarUrl(updated.avatar_url);
      await onUpdated();
    } catch (err) {
      setAvatarError(err instanceof ApiError ? err.message : "Could not remove that photo.");
    } finally {
      setAvatarBusy(false);
    }
  }

  const [name, setName] = useState(user.name);
  const [email, setEmail] = useState(user.email);
  const [phone, setPhone] = useState(user.phone ?? "");
  const [profileTouched, setProfileTouched] = useState(false);
  const [savingProfile, setSavingProfile] = useState(false);
  const [profileError, setProfileError] = useState<string | null>(null);
  const [profileSuccess, setProfileSuccess] = useState(false);

  const nameErr = validateName(name, "Full name");
  const emailErr = validateEmail(email);
  const phoneErr = validatePhone(phone, false);
  const profileValid = !nameErr && !emailErr && !phoneErr;
  const profileDirty = name !== user.name || email !== user.email || phone !== (user.phone ?? "");

  async function saveProfile(event: FormEvent) {
    event.preventDefault();
    setProfileTouched(true);
    if (!profileValid || !profileDirty) return;

    setSavingProfile(true);
    setProfileError(null);
    setProfileSuccess(false);
    try {
      await updateMyProfile({ name, email, phone: phone || null });
      await onUpdated();
      setProfileSuccess(true);
    } catch (err) {
      setProfileError(
        err instanceof ApiError ? Object.values(err.errors ?? {})[0]?.[0] ?? err.message : "Could not save changes.",
      );
    } finally {
      setSavingProfile(false);
    }
  }

  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [showCurrent, setShowCurrent] = useState(false);
  const [showNew, setShowNew] = useState(false);
  const [passwordTouched, setPasswordTouched] = useState(false);
  const [savingPassword, setSavingPassword] = useState(false);
  const [passwordError, setPasswordError] = useState<string | null>(null);
  const [passwordSuccess, setPasswordSuccess] = useState(false);

  const newPasswordErr = validatePassword(newPassword, true);
  const confirmErr = confirmPassword && confirmPassword !== newPassword ? "Passwords do not match." : undefined;
  const passwordValid = currentPassword.length > 0 && !newPasswordErr && !!confirmPassword && !confirmErr;

  async function savePassword(event: FormEvent) {
    event.preventDefault();
    setPasswordTouched(true);
    if (!passwordValid) return;

    setSavingPassword(true);
    setPasswordError(null);
    setPasswordSuccess(false);
    try {
      await updateMyProfile({
        password: newPassword,
        password_confirmation: confirmPassword,
        current_password: currentPassword,
      });
      setCurrentPassword("");
      setNewPassword("");
      setConfirmPassword("");
      setPasswordTouched(false);
      setPasswordSuccess(true);
    } catch (err) {
      setPasswordError(
        err instanceof ApiError
          ? (err.errors?.current_password?.[0] ?? Object.values(err.errors ?? {})[0]?.[0] ?? err.message)
          : "Could not change password.",
      );
    } finally {
      setSavingPassword(false);
    }
  }

  return (
    <>
      <div className="rounded-xl border border-neutral-200 bg-white p-5">
        <div className="mb-4 flex items-center gap-2">
          <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
          <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Photo</h2>
        </div>

        <div className="flex items-center gap-4">
          <Avatar name={name || user.name} url={avatarUrl} size={64} />
          <div className="space-y-2">
            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                onClick={() => fileInputRef.current?.click()}
                disabled={avatarBusy}
                className="font-heading rounded-md border border-neutral-300 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
              >
                {avatarBusy ? "Working…" : avatarUrl ? "Change photo" : "Upload photo"}
              </button>
              {avatarUrl && (
                <button
                  type="button"
                  onClick={handleAvatarRemove}
                  disabled={avatarBusy}
                  className="font-heading rounded-md border border-neutral-300 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-neutral-500 hover:bg-neutral-50 disabled:opacity-50"
                >
                  Remove
                </button>
              )}
            </div>
            <p className="text-xs text-neutral-400">JPG, PNG, or WEBP. Max 2MB.</p>
            {avatarError && <p className={errorClass}>{avatarError}</p>}
          </div>
          <input
            ref={fileInputRef}
            type="file"
            accept="image/jpeg,image/jpg,image/png,image/webp"
            onChange={handleAvatarChange}
            className="hidden"
          />
        </div>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-5">
        <div className="mb-4 flex items-center gap-2">
          <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
          <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Profile</h2>
        </div>

        <form onSubmit={saveProfile} noValidate className="space-y-3">
          <div>
            <label htmlFor={nameId} className={labelClass}>Full name</label>
            <input id={nameId} className={inputClass} value={name} onChange={(e) => setName(e.target.value)} />
            {profileTouched && nameErr && <p className={errorClass}>{nameErr}</p>}
          </div>
          <div>
            <label htmlFor={emailId} className={labelClass}>Email</label>
            <input id={emailId} className={inputClass} type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
            {profileTouched && emailErr && <p className={errorClass}>{emailErr}</p>}
          </div>
          <div>
            <label htmlFor={phoneId} className={labelClass}>Phone</label>
            <input id={phoneId} className={inputClass} value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="(000) 000-0000" />
            {profileTouched && phoneErr && <p className={errorClass}>{phoneErr}</p>}
          </div>

          {profileError && <p className="text-xs text-red-600">{profileError}</p>}
          {profileSuccess && !profileDirty && <p className="text-xs text-green-700">Saved.</p>}

          <button
            type="submit"
            disabled={savingProfile || !profileDirty}
            className="font-heading rounded-md bg-red-600 px-4 py-2 text-xs font-bold uppercase tracking-wide text-white hover:bg-red-700 disabled:opacity-50"
          >
            {savingProfile ? "Saving…" : "Save changes"}
          </button>
        </form>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-5">
        <div className="mb-4 flex items-center gap-2">
          <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
          <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Password</h2>
        </div>

        <form onSubmit={savePassword} noValidate className="space-y-3">
          <div>
            <label htmlFor={currentPasswordId} className={labelClass}>Current password</label>
            <div className="relative">
              <input
                id={currentPasswordId}
                className={inputClass}
                type={showCurrent ? "text" : "password"}
                value={currentPassword}
                onChange={(e) => setCurrentPassword(e.target.value)}
              />
              <button
                type="button"
                onClick={() => setShowCurrent((v) => !v)}
                aria-label={showCurrent ? "Hide password" : "Show password"}
                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-neutral-400 hover:text-neutral-600"
              >
                {showCurrent ? <EyeOffIcon className="h-4 w-4" /> : <EyeIcon className="h-4 w-4" />}
              </button>
            </div>
          </div>
          <div>
            <label htmlFor={newPasswordId} className={labelClass}>New password</label>
            <div className="relative">
              <input
                id={newPasswordId}
                className={inputClass}
                type={showNew ? "text" : "password"}
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
              />
              <button
                type="button"
                onClick={() => setShowNew((v) => !v)}
                aria-label={showNew ? "Hide password" : "Show password"}
                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-neutral-400 hover:text-neutral-600"
              >
                {showNew ? <EyeOffIcon className="h-4 w-4" /> : <EyeIcon className="h-4 w-4" />}
              </button>
            </div>
            {passwordTouched && newPassword && newPasswordErr && <p className={errorClass}>{newPasswordErr}</p>}
          </div>
          <div>
            <label htmlFor={confirmPasswordId} className={labelClass}>Confirm new password</label>
            <input
              id={confirmPasswordId}
              className={inputClass}
              type={showNew ? "text" : "password"}
              value={confirmPassword}
              onChange={(e) => setConfirmPassword(e.target.value)}
            />
            {passwordTouched && confirmErr && <p className={errorClass}>{confirmErr}</p>}
          </div>

          {passwordError && <p className="text-xs text-red-600">{passwordError}</p>}
          {passwordSuccess && <p className="text-xs text-green-700">Password changed.</p>}

          <button
            type="submit"
            disabled={savingPassword || !passwordValid}
            className="font-heading rounded-md bg-red-600 px-4 py-2 text-xs font-bold uppercase tracking-wide text-white hover:bg-red-700 disabled:opacity-50"
          >
            {savingPassword ? "Saving…" : "Change password"}
          </button>
        </form>
      </div>
    </>
  );
}
