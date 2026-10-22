"use client";

import axios from "axios";
import Link from "next/link";
import { useRouter } from "next/navigation";
import {
  useEffect,
  useState,
  type ChangeEvent,
  type FormEvent,
} from "react";
import {
  AlertTriangle,
  KeyRound,
  Trash2,
  UserRound,
} from "lucide-react";
import { toast } from "sonner";

import { useAuth } from "@/app/Providers/AuthProvider";
import { WorkspaceNav } from "@/app/features/workspaces/WorkspaceNav";
import type { User } from "@/lib/auth";
import {
  deleteAccount,
  removeProfilePhoto,
  updatePassword,
  updateProfile,
  uploadProfilePhoto,
} from "@/lib/profile";

const inputClass =
  "h-11 w-full rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-3.5 text-sm text-[#20202A] dark:text-[#ececf1] outline-none transition placeholder:text-[#A3A2AC] dark:placeholder:text-[#7e7e88] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]";

const labelClass =
  "text-sm font-medium text-[#4A4D57] dark:text-[#9C9FA8]";

const cardClass =
  "rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] p-6 shadow-sm sm:p-8";

function errorMessage(error: unknown, fallback: string): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as
      | { message?: string; errors?: Record<string, string[]> }
      | undefined;

    const firstValidationError = data?.errors
      ? Object.values(data.errors)[0]?.[0]
      : undefined;

    return firstValidationError ?? data?.message ?? fallback;
  }

  return fallback;
}

/**
 * The editable part of the profile.
 *
 * It owns the form state instead of syncing it from `user` in an effect: the
 * page only renders this once a user is loaded, and `key={user.id}` gives a
 * fresh form if the account ever changes.
 */
function ProfileDetails({
  user,
  refreshUser,
}: {
  user: User;
  refreshUser: () => Promise<void>;
}) {
  const [username, setUsername] = useState(user.username);
  const [email, setEmail] = useState(user.email);
  const [bio, setBio] = useState(user.bio ?? "");
  const [location, setLocation] = useState(user.location ?? "");
  const [photoUrl, setPhotoUrl] = useState(user.photo_url);
  const [photoBusy, setPhotoBusy] = useState(false);
  const [profileError, setProfileError] = useState<string | null>(null);
  const [savingProfile, setSavingProfile] = useState(false);

  async function handleProfileSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    setSavingProfile(true);
    setProfileError(null);

    try {
      const result = await updateProfile({
        username,
        email,
        bio: bio.trim() || null,
        location: location.trim() || null,
      });
      await refreshUser();
      toast.success(result.message);
    } catch (error) {
      setProfileError(
        errorMessage(error, "Could not update your profile."),
      );
    } finally {
      setSavingProfile(false);
    }
  }

  async function handlePhotoSelected(event: ChangeEvent<HTMLInputElement>) {
    const file = event.currentTarget.files?.[0];
    event.currentTarget.value = "";

    if (!file) {
      return;
    }

    setPhotoBusy(true);
    setProfileError(null);

    try {
      const result = await uploadProfilePhoto(file);
      setPhotoUrl(result.user.photo_url);
      await refreshUser();
      toast.success(result.message);
    } catch (error) {
      setProfileError(
        errorMessage(error, "Could not update your photo."),
      );
    } finally {
      setPhotoBusy(false);
    }
  }

  async function handleRemovePhoto() {
    setPhotoBusy(true);
    setProfileError(null);

    try {
      const result = await removeProfilePhoto();
      setPhotoUrl(result.user.photo_url);
      await refreshUser();
      toast.success(result.message);
    } catch (error) {
      setProfileError(
        errorMessage(error, "Could not remove your photo."),
      );
    } finally {
      setPhotoBusy(false);
    }
  }

  return (
    <>
      <div className="mb-6 flex flex-col gap-4 rounded-xl border border-[#E7E6ED] dark:border-[#2a2a31] bg-[#F2F0EA]/60 dark:bg-[#121214]/60 p-4 sm:flex-row sm:items-center">
        <div className="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#EEEDFF] dark:bg-[#262449] text-2xl font-semibold text-[#5146E5]">
          {photoUrl ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={photoUrl}
              alt={`${user.username}'s profile photo`}
              className="h-full w-full object-cover"
            />
          ) : (
            user.username.charAt(0).toUpperCase()
          )}
        </div>

        <div className="flex min-w-0 flex-col gap-2">
          <div className="flex flex-wrap items-center gap-2">
            <label
              className={`inline-flex items-center justify-center rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-4 py-2 text-sm font-medium text-[#20202A] dark:text-[#ececf1] transition hover:border-[#5146E5] hover:text-[#5146E5] ${
                photoBusy
                  ? "pointer-events-none opacity-50"
                  : "cursor-pointer"
              }`}
            >
              {photoBusy ? "Uploading…" : "Change photo"}
              <input
                type="file"
                accept="image/jpeg,image/png,image/webp"
                className="sr-only"
                onChange={handlePhotoSelected}
                disabled={photoBusy}
              />
            </label>

            {photoUrl && (
              <button
                type="button"
                onClick={handleRemovePhoto}
                disabled={photoBusy}
                className="rounded-lg px-3 py-2 text-sm font-medium text-[#B13D55] dark:text-[#f0a0b0] transition hover:bg-[#FCECEF] dark:hover:bg-[#43212b] disabled:cursor-not-allowed disabled:opacity-50"
              >
                Remove
              </button>
            )}
          </div>

          <p className="text-xs leading-relaxed text-[#9897A1] dark:text-[#8b8b94]">
            PNG, JPG or WebP, up to 2 MB.
          </p>
        </div>
      </div>

      <form onSubmit={handleProfileSubmit} className="flex flex-col gap-5">
        <div className="flex flex-col gap-1.5">
          <label htmlFor="username" className={labelClass}>
            Username
          </label>
          <input
            id="username"
            type="text"
            required
            minLength={3}
            maxLength={30}
            pattern="[a-zA-Z0-9](?:[a-zA-Z0-9_-]*[a-zA-Z0-9])?"
            autoComplete="username"
            value={username}
            onChange={(event) => setUsername(event.target.value)}
            className={inputClass}
          />
          <p className="text-xs text-[#9897A1] dark:text-[#8b8b94]">
            3–30 characters: letters, numbers, hyphens, and underscores.
          </p>
        </div>

        <div className="flex flex-col gap-1.5">
          <label htmlFor="email" className={labelClass}>
            Email
          </label>
          <input
            id="email"
            type="email"
            required
            autoComplete="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            className={inputClass}
          />
        </div>

        <div className="flex flex-col gap-1.5">
          <label htmlFor="bio" className={labelClass}>
            Bio
          </label>
          <textarea
            id="bio"
            rows={4}
            maxLength={1000}
            value={bio}
            onChange={(event) => setBio(event.target.value)}
            placeholder="A short introduction other workspace viewers will see."
            className={`${inputClass} h-auto min-h-28 resize-y py-3`}
          />
          <p className="text-xs text-[#9897A1] dark:text-[#8b8b94]">
            {bio.length}/1000 characters
          </p>
        </div>

        <div className="flex flex-col gap-1.5">
          <label htmlFor="location" className={labelClass}>
            Location
          </label>
          <input
            id="location"
            type="text"
            maxLength={120}
            autoComplete="address-level2"
            value={location}
            onChange={(event) => setLocation(event.target.value)}
            placeholder="City or region"
            className={inputClass}
          />
        </div>

        {profileError && (
          <p
            role="alert"
            className="rounded-xl border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#43212b] px-4 py-3 text-sm text-[#B13D55] dark:text-[#f0a0b0]"
          >
            {profileError}
          </p>
        )}

        <div>
          <button
            type="submit"
            disabled={savingProfile}
            className="inline-flex items-center justify-center rounded-lg bg-[#5146E5] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[#4338CA] disabled:cursor-not-allowed disabled:opacity-60"
          >
            {savingProfile ? "Saving…" : "Save changes"}
          </button>
        </div>
      </form>
    </>
  );
}

export default function ProfilePage() {
  const router = useRouter();
  const { user, loading: authLoading, refreshUser } = useAuth();

  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [passwordError, setPasswordError] = useState<string | null>(null);
  const [savingPassword, setSavingPassword] = useState(false);

  const [deleteEmail, setDeleteEmail] = useState("");
  const [deletePassword, setDeletePassword] = useState("");
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [deleting, setDeleting] = useState(false);

  useEffect(() => {
    if (!authLoading && !user) {
      router.replace("/auth/login");
    }
  }, [authLoading, user, router]);

  async function handlePasswordSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    setSavingPassword(true);
    setPasswordError(null);

    try {
      const message = await updatePassword({
        currentPassword,
        password: newPassword,
        passwordConfirmation,
      });

      setCurrentPassword("");
      setNewPassword("");
      setPasswordConfirmation("");
      toast.success(message);
    } catch (error) {
      setPasswordError(
        errorMessage(error, "Could not update your password."),
      );
    } finally {
      setSavingPassword(false);
    }
  }

  async function handleDelete(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    setDeleting(true);
    setDeleteError(null);

    try {
      await deleteAccount({
        email: deleteEmail,
        password: user?.has_password ? deletePassword : undefined,
      });

      await refreshUser();
      toast.success("Account deleted.");
      router.replace("/");
    } catch (error) {
      setDeleteError(
        errorMessage(error, "Could not delete your account."),
      );
      setDeleting(false);
    }
  }

  if (authLoading || !user) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-[#F2F0EA] dark:bg-[#121214] font-sans">
        <div className="flex items-center gap-3 text-sm text-[#686873] dark:text-[#b0b0b9]">
          <span className="h-2 w-2 rounded-full bg-[#5146E5]" />
          Loading profile…
        </div>
      </main>
    );
  }

  const canConfirmDelete =
    deleteEmail.trim().toLowerCase() === user.email.toLowerCase() &&
    (!user.has_password || deletePassword.length > 0);

  return (
    <div className="flex min-h-screen flex-col bg-[#F2F0EA] dark:bg-[#121214] font-sans text-[#20202A] dark:text-[#ececf1]">
      <WorkspaceNav />

      <main className="mx-auto w-full max-w-3xl flex-1 px-6 py-10 sm:px-10 sm:py-12">
        <header>
          <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
            Account
          </p>
          <h1 className="mt-2 text-3xl font-semibold tracking-[-0.03em] sm:text-4xl">
            Your profile
          </h1>
          <p className="mt-3 max-w-2xl text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9] sm:text-base">
            Manage your photo, username, bio, location and email, change your
            password, or delete the account entirely.
          </p>
          <Link
            href={`/users/${user.username}`}
            className="mt-4 inline-flex text-sm font-medium text-[#5146E5] transition hover:text-[#4338CA]"
          >
            View your public profile
          </Link>
        </header>

        <section className={`mt-9 ${cardClass}`}>
          <div className="mb-6 flex items-center gap-3">
            <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-[#EEEDFF] dark:bg-[#262449] text-[#5146E5]">
              <UserRound className="h-5 w-5" aria-hidden="true" />
            </span>
            <div>
              <h2 className="text-lg font-semibold tracking-tight">
                Profile details
              </h2>
              <p className="text-sm text-[#686873] dark:text-[#b0b0b9]">
                Changing your email requires verifying the new address.
              </p>
            </div>
          </div>

          {!user.email_verified_at && (
            <p
              role="status"
              className="mb-5 rounded-xl border border-[#F0DFC0] dark:border-[#4a3d20] bg-[#FBF3E2] dark:bg-[#322711] px-4 py-3 text-sm text-[#8A6A1F] dark:text-[#e0b060]"
            >
              Your email address is not verified yet.
            </p>
          )}

          <ProfileDetails
            key={user.id}
            user={user}
            refreshUser={refreshUser}
          />
        </section>

        <section className={`mt-6 ${cardClass}`}>
          <div className="mb-6 flex items-center gap-3">
            <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-[#EAF7F6] dark:bg-[#143036] text-[#159A9C] dark:text-[#3ac6c8]">
              <KeyRound className="h-5 w-5" aria-hidden="true" />
            </span>
            <div>
              <h2 className="text-lg font-semibold tracking-tight">
                Password
              </h2>
              <p className="text-sm text-[#686873] dark:text-[#b0b0b9]">
                {user.has_password
                  ? "Use a password you don't use anywhere else."
                  : "You signed up with Google, set a password to also log in with email."}
              </p>
            </div>
          </div>

          <form onSubmit={handlePasswordSubmit} className="flex flex-col gap-5">
            {user.has_password && (
              <div className="flex flex-col gap-1.5">
                <label htmlFor="current_password" className={labelClass}>
                  Current password
                </label>
                <input
                  id="current_password"
                  type="password"
                  required
                  autoComplete="current-password"
                  value={currentPassword}
                  onChange={(event) => setCurrentPassword(event.target.value)}
                  className={inputClass}
                />
              </div>
            )}

            <div className="flex flex-col gap-1.5">
              <label htmlFor="new_password" className={labelClass}>
                New password
              </label>
              <input
                id="new_password"
                type="password"
                required
                autoComplete="new-password"
                value={newPassword}
                onChange={(event) => setNewPassword(event.target.value)}
                className={inputClass}
              />
            </div>

            <div className="flex flex-col gap-1.5">
              <label htmlFor="password_confirmation" className={labelClass}>
                Confirm new password
              </label>
              <input
                id="password_confirmation"
                type="password"
                required
                autoComplete="new-password"
                value={passwordConfirmation}
                onChange={(event) =>
                  setPasswordConfirmation(event.target.value)
                }
                className={inputClass}
              />
            </div>

            {passwordError && (
              <p
                role="alert"
                className="rounded-xl border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#43212b] px-4 py-3 text-sm text-[#B13D55] dark:text-[#f0a0b0]"
              >
                {passwordError}
              </p>
            )}

            <div>
              <button
                type="submit"
                disabled={savingPassword}
                className="inline-flex items-center justify-center rounded-lg bg-[#5146E5] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[#4338CA] disabled:cursor-not-allowed disabled:opacity-60"
              >
                {savingPassword ? "Updating…" : "Update password"}
              </button>
            </div>
          </form>
        </section>

        <section className="mt-6 rounded-2xl border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF]/50 dark:bg-[#2a161a] p-6 shadow-sm sm:p-8">
          <div className="mb-6 flex items-center gap-3">
            <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-[#FCECEF] dark:bg-[#43212b] text-[#B13D55] dark:text-[#f08098]">
              <AlertTriangle className="h-5 w-5" aria-hidden="true" />
            </span>
            <div>
              <h2 className="text-lg font-semibold tracking-tight text-[#B13D55] dark:text-[#f0a0b0]">
                Delete account
              </h2>
              <p className="text-sm text-[#8C5966] dark:text-[#c99aa6]">
                This permanently removes your account, the workspaces you own,
                and their entries. It cannot be undone.
              </p>
            </div>
          </div>

          <form onSubmit={handleDelete} className="flex flex-col gap-5">
            <div className="flex flex-col gap-1.5">
              <label htmlFor="delete_email" className={labelClass}>
                Type <span className="font-semibold">{user.email}</span> to
                confirm
              </label>
              <input
                id="delete_email"
                type="email"
                required
                autoComplete="off"
                value={deleteEmail}
                onChange={(event) => setDeleteEmail(event.target.value)}
                className={inputClass}
              />
            </div>

            {user.has_password && (
              <div className="flex flex-col gap-1.5">
                <label htmlFor="delete_password" className={labelClass}>
                  Password
                </label>
                <input
                  id="delete_password"
                  type="password"
                  required
                  autoComplete="current-password"
                  value={deletePassword}
                  onChange={(event) => setDeletePassword(event.target.value)}
                  className={inputClass}
                />
              </div>
            )}

            {deleteError && (
              <p
                role="alert"
                className="rounded-xl border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#43212b] px-4 py-3 text-sm text-[#B13D55] dark:text-[#f0a0b0]"
              >
                {deleteError}
              </p>
            )}

            <div>
              <button
                type="submit"
                disabled={deleting || !canConfirmDelete}
                className="inline-flex items-center gap-2 rounded-lg bg-[#B13D55] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[#983349] disabled:cursor-not-allowed disabled:opacity-50"
              >
                <Trash2 className="h-4 w-4" aria-hidden="true" />
                {deleting ? "Deleting…" : "Delete my account"}
              </button>
            </div>
          </form>
        </section>
      </main>
    </div>
  );
}
