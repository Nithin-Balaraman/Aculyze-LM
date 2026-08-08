import { LoginForm } from "@/components/LoginForm";

export default function LoginPage() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
      <div className="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-8 shadow-sm">
        <h1 className="mb-1 text-xl font-semibold text-brand-navy">Aculyze Lead Management</h1>
        <p className="mb-6 text-sm text-slate-500">Sign in to continue.</p>
        <LoginForm />
      </div>
    </div>
  );
}
