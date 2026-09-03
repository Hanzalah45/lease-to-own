import { SectionHeading } from "@/components/dashboard/SectionHeading";

export function AssignmentCard({
  salesperson,
  reviewedBy,
  createdBy,
}: {
  salesperson: string;
  reviewedBy: string;
  createdBy?: string;
}) {
  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <SectionHeading title="Assignment" />
      <div className="mt-4 space-y-2.5 text-sm">
        {createdBy && (
          <div className="flex items-center justify-between">
            <span className="text-neutral-500">Created by</span>
            <span className="font-semibold text-neutral-900">{createdBy}</span>
          </div>
        )}
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
