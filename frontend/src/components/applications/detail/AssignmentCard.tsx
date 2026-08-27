import { SectionHeading } from "@/components/dashboard/SectionHeading";

export function AssignmentCard({ salesperson, reviewedBy }: { salesperson: string; reviewedBy: string }) {
  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <SectionHeading title="Assignment" />
      <div className="mt-4 space-y-2.5 text-sm">
        <div className="flex items-center justify-between">
          <span className="text-neutral-500">Salesperson</span>
          <span className="font-semibold text-neutral-900">{salesperson}</span>
        </div>
        <div className="flex items-center justify-between">
          <span className="text-neutral-500">Reviewed by</span>
          <span className="font-semibold text-neutral-900">{reviewedBy}</span>
        </div>
      </div>
    </div>
  );
}
