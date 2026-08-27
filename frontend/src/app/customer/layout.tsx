import { CustomerTopNav } from "@/components/customer/CustomerTopNav";
import { Footer } from "@/components/layout/Footer";

export default function CustomerLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex flex-1 flex-col bg-neutral-50">
      <CustomerTopNav />
      <main className="flex-1 px-4 py-4 sm:px-8 sm:py-6 lg:px-16">{children}</main>
      <Footer />
    </div>
  );
}
