import { AdminTopNav } from "@/components/admin/AdminTopNav";

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex flex-1 flex-col bg-neutral-50">
      <AdminTopNav />
      <main className="flex-1 px-4 py-4 sm:px-8 sm:py-6 lg:px-16">{children}</main>
    </div>
  );
}
