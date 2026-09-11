"use client";

import Image from "next/image";
import { money, TRACKING_DEVICE_FEE } from "@/components/applications/wizard/types";
import type { LeaseAgreement } from "@/types/lease-agreement";
import type { CustomerProfile } from "@/types/auth";

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between border-b border-neutral-100 py-2.5 last:border-0 print:break-inside-avoid">
      <span className="text-sm text-neutral-500">{label}</span>
      <span className="text-sm font-bold text-neutral-900">{value}</span>
    </div>
  );
}

function SubHeader({ children }: { children: string }) {
  return (
    <div className="rounded-md bg-neutral-100 px-3 py-2 text-xs font-bold uppercase tracking-wide text-neutral-500 print:break-after-avoid">
      {children}
    </div>
  );
}

function num(value: string | number | null | undefined): number {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n : 0;
}

function Initials({ name, date }: { name: string; date: string | null }) {
  const initials = name
    .trim()
    .split(/\s+/)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("");
  return (
    <div className="mt-3 border-t border-neutral-100 pt-2 text-right text-[11px] text-neutral-400 print:break-inside-avoid">
      Initials: <span className="font-bold text-neutral-900">{initials}</span>
      {date ? ` · ${new Date(date).toLocaleDateString()}` : ""}
    </div>
  );
}

function LdwRow({
  category,
  description,
  covered,
  notes,
}: {
  category: string;
  description: string;
  covered: boolean;
  notes: string;
}) {
  return (
    <tr className="border-b border-neutral-100 last:border-0 print:break-inside-avoid">
      <td className="py-2 pr-3 align-top font-semibold text-neutral-800">{category}</td>
      <td className="py-2 pr-3 align-top text-neutral-500">{description}</td>
      <td className={`py-2 pr-3 align-top font-bold ${covered ? "text-green-700" : "text-red-600"}`}>
        {covered ? "Yes" : "No"}
      </td>
      <td className="py-2 align-top text-neutral-500">{notes}</td>
    </tr>
  );
}

function ArbitrationRow({ q, a }: { q: string; a: string }) {
  return (
    <div className="grid grid-cols-1 gap-1 border-b border-neutral-100 py-2.5 last:border-0 sm:grid-cols-[1fr_2fr] sm:gap-4 print:break-inside-avoid">
      <span className="text-xs font-bold text-neutral-700">{q}</span>
      <span className="text-xs text-neutral-500">{a}</span>
    </div>
  );
}

function SignatureBlock({ name, date, status }: { name: string; date: string | null; status: string }) {
  return (
    <div className="mt-5 grid grid-cols-1 gap-4 border-t-2 border-neutral-900 pt-4 sm:grid-cols-3 print:break-inside-avoid">
      <div>
        <p className="text-xs font-bold uppercase tracking-wide text-neutral-400">Signed by</p>
        <p className="mt-1 text-sm font-semibold text-neutral-900">{date ? name : "—"}</p>
      </div>
      <div>
        <p className="text-xs font-bold uppercase tracking-wide text-neutral-400">Timestamp</p>
        <p className="mt-1 text-sm font-semibold text-neutral-900">{date ? new Date(date).toLocaleString() : "—"}</p>
      </div>
      <div>
        <p className="text-xs font-bold uppercase tracking-wide text-neutral-400">Status</p>
        <p className={`mt-1 text-sm font-semibold ${date ? "text-green-600" : "text-amber-600"}`}>
          {date ? status : "Awaiting signature"}
        </p>
      </div>
    </div>
  );
}

export function LeaseAgreementDocument({
  lease,
  customerName,
  customerAddress,
  profile,
}: {
  lease: LeaseAgreement;
  customerName: string;
  customerAddress: string;
  profile?: CustomerProfile | null;
}) {
  const equipment = lease.equipment_unit;
  const cashPrice = num(lease.cash_price);
  const monthlyRental = num(lease.monthly_rental_payment);
  const ldwAmount = num(lease.ldw_amount);
  const salesTax = num(lease.sales_tax_amount);
  const totalMonthly = num(lease.total_monthly_payment);
  const securityDeposit = num(lease.security_deposit);
  const totalDueToday = securityDeposit + TRACKING_DEVICE_FEE + totalMonthly;
  const taxRatePct = (num(lease.sales_tax_rate) * 100).toFixed(2);
  const signedName = lease.contract ? customerName : "";
  const signedDate = lease.contract?.signed_at ?? null;

  // Joel doesn't have a business phone number set up yet (2026-09-11) — both
  // stay blank until then, and this line reads the same as it does today.
  const companyPhone = process.env.NEXT_PUBLIC_COMPANY_PHONE;
  const companyAddress = process.env.NEXT_PUBLIC_COMPANY_ADDRESS;
  const contactUsAnswer = `By mail${companyAddress ? ` at ${companyAddress}` : ""}${
    companyPhone ? `, or by phone at ${companyPhone}` : ""
  }. You can call us or use certified mail to confirm receipt.`;

  return (
    <div className="space-y-6">
      <div className="rounded-xl border border-neutral-200 bg-white p-6 print:break-inside-avoid">
        <div className="mb-4 flex items-center justify-between">
          <Image
            src="/prostartLeasing.png"
            alt="Prostart Leasing"
            width={110}
            height={71}
            className="h-10 w-auto rounded bg-neutral-950 p-1.5"
          />
          <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">
            Customer information &amp; authorization
          </h2>
        </div>
        <div className="border-t-2 border-neutral-900 pt-4">
          <SubHeader>Contact information</SubHeader>
          <div className="mt-2 grid grid-cols-1 gap-x-8 sm:grid-cols-2">
            <Row label="Renter Name" value={customerName} />
            <Row label="Mailing Address" value={customerAddress || "—"} />
          </div>
        </div>

        {profile && (
          <div className="mt-5 print:break-inside-avoid">
            <SubHeader>Physical location of leased property &amp; source of income</SubHeader>
            <div className="mt-2 grid grid-cols-1 gap-x-8 sm:grid-cols-2">
              <Row label="Physical Address" value={customerAddress || "—"} />
              <Row label="How Long at Residence" value={profile.years_at_residence || "—"} />
              <Row
                label="Own / Rent"
                value={profile.residence_type ? profile.residence_type.replace("_", " ") : "—"}
              />
              {profile.residence_type?.includes("rent") ? (
                <>
                  <Row label="Monthly Rent Payment" value={profile.monthly_rent ? money(num(profile.monthly_rent)) : "—"} />
                  <Row label="Landlord Name" value={profile.landlord_name || "—"} />
                  <Row label="Landlord Phone" value={profile.landlord_phone || "—"} />
                </>
              ) : (
                <Row label="Mortgage Payment" value={profile.mortgage_amount ? money(num(profile.mortgage_amount)) : "—"} />
              )}
              <Row
                label="Income Source"
                value={profile.employment_status ? profile.employment_status.replace("_", " ") : "—"}
              />
              <Row label="Monthly Income" value={profile.monthly_income ? money(num(profile.monthly_income)) : "—"} />
              <Row label="Employer / Business Name" value={profile.employer_name || "—"} />
              <Row label="Employer / Business Phone" value={profile.employer_phone || "—"} />
            </div>
          </div>
        )}

        <div className="mt-5 print:break-inside-avoid">
          <SubHeader>Marketing &amp; communications consent</SubHeader>
          <p className="mt-2 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            <strong>Account Transaction Calls.</strong> By signing below, you authorize Prostart Leasing to contact
            you at the phone number(s) and email you provided, including your cell phone, using auto-dialers,
            prerecorded voice messages, and text messages, for account information, payment reminders, and
            collection efforts related to this Agreement. You may withdraw this consent at any time by notifying us
            in writing.
          </p>
          <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            <strong>Marketing Calls &amp; Texts.</strong> By signing below, you also authorize Prostart Leasing to
            contact you with marketing and telemarketing calls and text messages at the number(s) provided, using an
            automatic telephone dialing system or prerecorded messages. Consenting to marketing contact is not
            required to obtain a lease from us. Message and data rates may apply. You may opt out of marketing
            contact at any time by notifying us in writing, without affecting the rest of this Agreement.
          </p>
          <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            <strong>Email &amp; Verification.</strong> You authorize us to communicate with you via phone, mail, and
            email, and you authorize us to check and verify the information on this Agreement, including your income,
            employment history, and to obtain a background check. You certify that everything shown on this Agreement
            is true and correct.
          </p>
          <Initials name={signedName || customerName} date={signedDate} />
        </div>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-6 print:break-inside-avoid print:break-before-page">
        <h2 className="font-heading mb-2 border-b-2 border-neutral-900 pb-3 text-base font-bold uppercase tracking-wide text-neutral-900 print:break-after-avoid">
          Lease information summary
        </h2>

        <div className="mt-4">
          <SubHeader>Description of leased property</SubHeader>
          <div className="mt-2 grid grid-cols-1 gap-x-8 sm:grid-cols-2">
            <Row label="Cash Price / Retail" value={money(cashPrice)} />
            <Row label="Make" value={equipment?.model ?? "—"} />
            <Row label="Serial # / VIN" value={equipment?.serial_number ?? "—"} />
            <Row label="Description or Damage to Property" value={equipment?.condition_notes || "None noted"} />
          </div>
        </div>

        <div className="mt-5">
          <SubHeader>Lease details</SubHeader>
          <div className="mt-2 grid grid-cols-1 gap-x-8 sm:grid-cols-2">
            <Row label="Months to Ownership" value={String(lease.term_months)} />
            <Row label="Payment Due Day" value={lease.payment_due_day ?? "—"} />
            <Row label="Rental Payment" value={money(monthlyRental)} />
            <Row label={lease.ldw_selected ? "LDW (monthly)" : "No-LDW Surcharge (monthly)"} value={`${money(ldwAmount)} / mo`} />
            <Row label="Sales Tax" value={money(salesTax)} />
            <Row label="Total Monthly Payment" value={money(totalMonthly)} />
            <Row label="Security Deposit" value={money(securityDeposit)} />
            <Row label="Tracking Device Fee" value={money(TRACKING_DEVICE_FEE)} />
            <Row label="TOTAL DUE TODAY" value={money(totalDueToday)} />
            <Row label="AutoPay" value={lease.autopay_enabled ? "Yes" : "No"} />
            <Row label="Total Rental-Purchase Price" value={money(num(lease.total_rental_purchase_price))} />
          </div>
        </div>

        <p className="mt-5 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>Security Deposit &amp; Unit Hold.</strong> Your Security Deposit of {money(securityDeposit)} is
          non-refundable and holds the Property exclusively for you for thirty (30) days from the date of this
          Agreement. If you do not pick up the Property and complete your first rental payment within that time, the
          Security Deposit will be forfeited and the reservation cancelled.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>2. Lease Term &amp; Payment Schedule.</strong> This Agreement is for one month. The rental term
          begins on the date you pick up the Property and expires one month later. You can renew the Agreement for
          additional one-month terms at your option by making a monthly rental renewal payment on or before the
          expiration date. The Agreement will also renew if you continue to possess the Property until you notify us
          that you want to end the rental and make the Property available for pickup.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>3. Rental-Purchase Ownership.</strong> If you renew this Agreement for {lease.term_months} months
          in a row, you will have paid the Total Rental-Purchase Price of {money(num(lease.total_rental_purchase_price))},
          not including taxes or fees, and you will obtain ownership of the Property after the final payment. Or, you
          can exercise an early purchase option (&quot;EPO&quot;). Any time within 90 days of the date the rental term
          begins (the date you pick up the Property), your EPO price will be the Cash Price less all Rental Payments
          paid to date (excludes taxes and fees). After that time, your EPO price will be the Cash Price less 50% of
          Rental Payments
          scheduled to date, plus any Rental Payments still owed and any additional funds. You will not own the
          Property unless you pay the Total Rental-Purchase Price or exercise an EPO. The Total Rental-Purchase
          Price does not include other charges such as late fees, disclosed below. Taxes are also due at the time
          of exercising an EPO.
        </p>

        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>4. Maintenance, Repairs, and Loss of or Damage to the Property.</strong> During this Agreement, you
          are fully responsible for maintaining the Property in working order and usable condition. You are fully
          responsible for its condition and safety until it is returned to us. You are fully liable for all loss of,
          damage to or destruction of the Property from all causes, including, but not limited to, theft, vandalism,
          malicious mischief, or mysterious disappearance. If this Property is damaged, you must pay us promptly for
          the costs of repairs, not to exceed the EPO at the time the Property is returned to us. If the Property is
          lost or destroyed, you must pay us the amount of the EPO on the date of loss or the Cash Price, whichever
          is less.
        </p>

        <div className="mt-5 print:break-before-page">
          <SubHeader>4A. Loss damage waiver (LDW) coverage</SubHeader>
          <p className="mt-2 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            Loss Damage Waiver (&quot;LDW&quot;) is an optional protection that reduces your financial
            responsibility for accidental physical damage to the Property. LDW is not insurance and is not a
            warranty. LDW does not replace or modify any manufacturer&apos;s warranty. If you elect LDW and remain
            current on all Rental Payments, we will waive our right to require you to pay for accidental damage to
            the Property, subject to the terms below.
          </p>
          <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            <strong>LDW Interaction With Section 4.</strong> If LDW applies, your liability for accidental damage is
            waived up to the EPO amount. If LDW does not apply, you remain fully responsible for all loss of, damage
            to, or destruction of the Property as stated in Section 4, including liability up to the EPO or Cash
            Price, whichever is less.
          </p>
          <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            Any repeated or above-average failures, breakdowns, or damage will be presumed to result from misuse,
            neglect, or abuse unless proven otherwise. LDW coverage does not apply in these circumstances.
          </p>
          <div className="mt-3 overflow-x-auto">
            <table className="w-full min-w-[640px] border-collapse text-xs">
              <thead>
                <tr className="border-b border-neutral-200 text-left text-[10px] font-bold uppercase tracking-wide text-neutral-400">
                  <th className="py-2 pr-3">Category</th>
                  <th className="py-2 pr-3">Description</th>
                  <th className="py-2 pr-3">Covered by LDW?</th>
                  <th className="py-2">Notes / Customer Responsibility</th>
                </tr>
              </thead>
              <tbody>
                <LdwRow
                  category="Accidental Mechanical Failure"
                  description="Engine, drivetrain, pumps, clutches, PTO, transmission, internal mechanical components."
                  covered
                  notes="LDW waives repair costs up to the EPO amount."
                />
                <LdwRow
                  category="Accidental Electrical Failure"
                  description="Starter, solenoid, wiring harness, switches, ignition components."
                  covered
                  notes="LDW waives repair costs up to the EPO amount."
                />
                <LdwRow
                  category="Accidental Hydraulic Failure"
                  description="Hoses, pumps, valves, cylinders not damaged due to neglect."
                  covered
                  notes="LDW waives repair costs up to the EPO amount."
                />
                <LdwRow
                  category="Accidental Structural Damage"
                  description="Deck, spindles, housings, linkages, brackets, non-frame components."
                  covered
                  notes="LDW waives repair costs up to the EPO amount."
                />
                <LdwRow
                  category="Accidental Impact Damage"
                  description="Striking a stump, curb, root, rock, or other object."
                  covered
                  notes="LDW waives repair costs up to the EPO amount."
                />
                <LdwRow
                  category="Accidental Breakage"
                  description="Breakage of parts or assemblies during normal residential use."
                  covered
                  notes="LDW waives repair costs up to the EPO amount."
                />
                <LdwRow
                  category="Accidental Cosmetic Damage"
                  description="Scratches, dents, cracked plastic, non-functional cosmetic components."
                  covered
                  notes="LDW waives repair costs up to the EPO amount."
                />
                <LdwRow
                  category="Accidental Tire / Wheel / Blade / Deck Damage"
                  description="Damage caused by accidental contact or residential hazards."
                  covered
                  notes="LDW waives repair costs up to the EPO amount."
                />
                <LdwRow
                  category="Accidental Transport Damage"
                  description="Damage occurring while properly secured during towing or transport."
                  covered
                  notes="LDW waives repair costs up to the EPO amount."
                />
                <LdwRow
                  category="Accidental Third-Party Damage"
                  description="Damage caused unintentionally by another person."
                  covered
                  notes="LDW waives repair costs up to the EPO amount."
                />
                <LdwRow
                  category="Manufacturer Defects"
                  description="Defects covered under OEM warranty."
                  covered={false}
                  notes="Manufacturer warranty applies; LDW does not."
                />
                <LdwRow
                  category="Theft (Any Kind)"
                  description="Theft of the Property, with or without a police report."
                  covered={false}
                  notes="Theft of any kind is not covered under the LDW program. If the Property is stolen, you must pay the EPO amount. We recommend speaking with your insurance provider to ensure you have proper coverage for theft, fire, flood, and any other loss events related to the mower."
                />
                <LdwRow
                  category="Misuse / Abuse"
                  description="Overloading, unsafe operation, improper use."
                  covered={false}
                  notes="Customer owes repair costs or EPO amount."
                />
                <LdwRow
                  category="Commercial Use of Residential Equipment"
                  description="Using residential equipment for business or income."
                  covered={false}
                  notes="Customer owes repair costs or EPO amount."
                />
                <LdwRow
                  category="Improper Maintenance / Neglect"
                  description="Failure to perform required service intervals."
                  covered={false}
                  notes="Customer owes repair costs or EPO amount."
                />
                <LdwRow
                  category="Unauthorized Repairs"
                  description="Repairs performed without approval."
                  covered={false}
                  notes="Customer owes repair costs or EPO amount."
                />
                <LdwRow
                  category="Hour-Meter Tampering"
                  description="Resetting, disabling, altering the hour meter."
                  covered={false}
                  notes="LDW void; customer owes repair costs or EPO amount."
                />
                <LdwRow
                  category="GPS Tampering"
                  description="Removing, disabling, or altering the GPS device."
                  covered={false}
                  notes="LDW void; customer owes repair costs or EPO amount."
                />
                <LdwRow
                  category="Environmental Damage"
                  description="Fire, rollover, flooding, weather damage caused by negligence."
                  covered={false}
                  notes="Customer owes repair costs or EPO amount."
                />
                <LdwRow
                  category="Damage While Past Due"
                  description="Damage occurring while account is late or in default."
                  covered={false}
                  notes="LDW void; customer owes repair costs or EPO amount."
                />
                <LdwRow
                  category="Unauthorized User Operation"
                  description="Damage caused by someone not permitted to operate the Property."
                  covered={false}
                  notes="Customer owes repair costs or EPO amount."
                />
                <LdwRow
                  category="Removal From Approved Address"
                  description="Property moved without written permission."
                  covered={false}
                  notes="LDW void; customer owes repair costs or EPO amount."
                />
                <LdwRow
                  category="Disappearance / Non-Return"
                  description="Property not returned or made available for pickup."
                  covered={false}
                  notes="Customer owes EPO or Cash Price, whichever is less."
                />
              </tbody>
            </table>
          </div>
          <Initials name={signedName || customerName} date={signedDate} />
        </div>

        <p className="mt-5 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>5. Reinstatement.</strong> If you fail to make a timely renewal payment, you should contact us to
          arrange the return of the item and the Agreement will expire. You will incur Rental Payments until (1) the
          Property is returned to us or (2) you notify us that you want your rental to cease and make the item
          available to us for pickup. You can reinstate it without losing any rights or options previously acquired
          by making all payments due within 16 days of the renewal date. Or, if you return the Property to us within
          that time, then you will have 30 days from the date of return to reinstate by making all payments due. If
          you reinstate, we will furnish you with the same Property or property of comparable quality and condition.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>6. Termination.</strong> You may terminate this Agreement at any time by returning the Property to
          us or by making arrangements with us for its return.
        </p>
        <Initials name={signedName || customerName} date={signedDate} />

        <div className="mt-5 print:break-before-page">
          <SubHeader>7. Service, maintenance, hour-meter monitoring &amp; commercial-use restrictions</SubHeader>
          <p className="mt-2 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            <strong>Service, Maintenance, and Proper Use Requirements.</strong> You agree to operate and maintain the
            Equipment in accordance with the manufacturer&apos;s recommended service schedule, including oil changes,
            filter replacements, belt adjustments, blade maintenance, lubrication, and general cleaning. Failure to
            properly service the Equipment as required is a violation of this Agreement. Any damage, excessive wear,
            mechanical failure, or unsafe operation caused by improper or neglected maintenance will be your
            financial responsibility.
          </p>
          <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            If the Equipment becomes unusable due to lack of maintenance, misuse, or failure to follow manufacturer
            guidelines, you must pay the lesser of the Early Purchase Option amount or the Cash Price. All repair
            costs resulting from improper maintenance must be paid before the Agreement can be renewed.
          </p>
          <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            <strong>Hour-Meter Monitoring &amp; Usage Verification.</strong> All Equipment leased under this
            Agreement is equipped with an hour meter or similar usage-tracking device. Prostart Leasing may review
            hour-meter readings at any time to verify proper maintenance intervals, confirm appropriate use, and
            ensure the Equipment is being operated within its intended duty rating.
          </p>
          <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            The hour meter is considered the official record of use. Excessive hours, abnormal usage patterns, or
            readings inconsistent with residential use may indicate commercial operation or misuse. Any damage,
            failure, or excessive wear resulting from over-use or abusive operation will carry the same penalties as
            improper maintenance.
          </p>
          <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            Tampering with, disabling, resetting, or altering the hour meter is strictly prohibited and will be
            treated as intentional misuse. Tampering triggers the same penalties listed above, including
            responsibility for repair costs, immediate EPO liability if the Equipment is damaged beyond repair, and
            possible termination of the Agreement.
          </p>
          <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            <strong>Commercial-Use Restrictions &amp; Residential-Equipment Limitations.</strong> Prostart Leasing
            provides Equipment rated for specific types of use. Commercial use is permitted only when the Equipment
            leased is classified as commercial-grade. Using residential-grade or homeowner-grade Equipment for
            commercial, industrial, or income-producing purposes is strictly prohibited and constitutes misuse and a
            violation of this Agreement.
          </p>
          <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            If residential Equipment is used for commercial purposes, or if commercial Equipment is used beyond its
            intended duty rating, any resulting damage, mechanical failure, excessive wear, or loss will carry the
            same penalties as improper maintenance, including: full responsibility for all repair costs; immediate
            payment of the Early Purchase Option amount if the Equipment is damaged beyond repair; liability for the
            Cash Price if the Equipment is lost, destroyed, or not returned; and possible termination of the
            Agreement and repossession of the Equipment.
          </p>
          <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            You understand that misuse caused by operating Equipment outside its intended commercial or residential
            classification voids any warranty coverage and places full financial responsibility on you.
          </p>
        </div>

        <p className="mt-5 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>8. Other Charges.</strong> Any charge in addition to periodic payments must be reasonably related
          to the service performed.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>Late Fee.</strong> You authorize us to initiate payments from your verified payment method on or
          after each Payment Due Date in the amount described in this Agreement, including any accrued but unpaid
          rental charges. If your Rental Payment is not made within ten (10) days of the scheduled Payment Due Date,
          you authorize the assessment of a one-time late fee equal to ten percent (10%) of the Rental Payment,
          subject to a maximum charge of $30.00 and a minimum charge of $5.00, which will be added to the amount
          required to continue using the Property. This late fee will be charged only once per billing cycle and
          will not recur. You will receive notice at least ten (10) days before any payment is deducted if the
          total amount to be withdrawn exceeds your regularly scheduled payment by more than $30.00.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>Returned Check Charge.</strong> When allowed by law, if your check is returned to us for any
          reason, you must pay us a $30.00 returned check charge to cover our costs in processing your payment. If
          you bounce a check to us, you must make any future rental renewal or other payments to us from guaranteed
          funds, cashier&apos;s check, or money order, and not by personal check.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>Security Deposit Return.</strong> Your Security Deposit is non-refundable. If you believe a
          refund is warranted, you may contact us, and we will review your request on a case-by-case basis.
        </p>
        <Initials name={signedName || customerName} date={signedDate} />

        <p className="mt-5 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>9. Your Use of and the Nature of the Property.</strong> During this Agreement, you must use the
          Property in a safe, careful and proper manner. You cannot allow the Property to be used in violation of any
          applicable federal, state or local statute or other regulation. You must reimburse us for any damage to the
          Property caused by your misuse of the Property. You also agree that you will not alter the Property
          without our prior written permission. You represent that you are knowledgeable regarding the proper and
          safe use of the Property, including how to load, unload, and transport it safely.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>10. Default, Death, or Return.</strong> We may terminate this Agreement if you fail to fulfill your
          obligation to us. We may notify you of termination by any means. Unless we notify you, your estate, or your
          representative otherwise, receipt of each timely payment will renew the Agreement for additional one-month
          terms. You agree to pay us the lesser of the EPO or the fair market value of the Property if you fail to
          return it to us as provided for in this Agreement, plus all reasonable costs we incur getting the Property
          back.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>11. Other Persons, and Property.</strong> You are responsible for use of the Property in a safe
          manner. You fully assume the risk and agree to hold us harmless of loss, damage, injury, or death of any
          person and any property arising out of any use of the Property.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>12. Property Insurance.</strong> You agree to maintain physical damage insurance covering loss or
          damage to the Property written on an &quot;all-risk&quot; form. You agree to provide us proof of such
          insurance either through the policy itself or a certificate of insurance. The policy shall name us as a
          loss payee and shall include a waiver of subrogation in our favor.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          If you elected Loss Damage Waiver (&quot;LDW&quot;) coverage under this Agreement, you understand and
          agree that LDW is not insurance and does not replace or satisfy your obligation to maintain physical
          damage insurance. LDW only waives certain accidental damage charges as described in Section 4A. You still
          must obtain and maintain the required insurance through a provider of your choice, and you acknowledge
          that you have the right to obtain such insurance from any person or company that is reasonable to us.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>13. Equity.</strong> You understand that we own the Property until you buy it or obtain ownership
          as stated in this Agreement. During the rental term, you do not have any ownership interest in this
          Property, and you do not have the right to a refund of any Rental Payments when this Agreement is
          terminated.
        </p>
        <Initials name={signedName || customerName} date={signedDate} />

        <p className="mt-5 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid print:break-before-page">
          <strong>14. Location of Property and Inspection.</strong> You agree to keep this Property at the address
          shown above. If you remove this Property without our written permission, we have the right to terminate
          this Agreement. You agree that we have the right to inspect the Property with reasonable notice.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>15. Access Easement.</strong> For as long as you are in possession of the Property and until you
          obtain ownership, you grant us an access easement at the address where the Property is located, including
          any driveway, yard, gate, enclosure, garage, shed, or other area where the Property is stored or kept.
          This easement allows us to deliver the Property, inspect it, verify its location, and retrieve it when
          this Agreement terminates or if you are in default.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          You agree to ensure that the Property remains accessible for recovery at all times. You are fully
          responsible for preventing obstructions, including locked gates, blocked driveways, enclosed structures,
          animals, vehicles, or any other impediments that restrict access to the Property. You agree to remove any
          such obstructions immediately upon request.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          If you fail to make the Property reasonably accessible, you authorize us to enter the area where the
          Property is located for the limited purpose of recovering it, provided we do so without breaching the
          peace. You fully assume the risk of harm or damage that may arise if we must move or remove any
          obstruction to recover the Property. Failure to provide access may be treated as concealment or refusal
          to surrender the Property.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>16. Assignment.</strong> We may sell, transfer, or assign this Agreement. However, you have no
          right to sell, transfer, assign, pawn, or sub-lease the Property.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>17. Warranty.</strong> To the extent allowed by law, we disclaim any Warranty of Merchantability or
          Fitness for a Particular Purpose, either express or implied, on the Property. You are renting the Property
          &quot;as-is&quot; and &quot;with all faults,&quot; and you understand that we do not provide any warranty
          on the Property during the rental term. Any manufacturer&apos;s warranty that may exist applies only to
          defects covered by the manufacturer and does not apply to rental use, misuse, improper maintenance,
          commercial use of residential equipment, or any damage described in Sections 4, 4A, or 7 of this
          Agreement.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          If you elected Loss Damage Waiver (&quot;LDW&quot;), you understand that LDW is not a warranty and does
          not provide defect coverage. LDW only waives certain accidental damage charges as described in Section 4A
          and does not replace or modify any manufacturer&apos;s warranty.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          If you obtain ownership of the Property, we will transfer to you any unexpired manufacturer&apos;s
          warranty on the Property, if permitted by the terms of the warranty. No manufacturer&apos;s warranty is
          transferred or available to you until ownership is obtained.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>18. Miscellaneous Provisions.</strong> You understand that no changes may be made to this
          Agreement except in writing. Time is of the essence in this Agreement. This Agreement is not effective
          until you sign it and we receive the required Security Deposit, which holds the Property exclusively for
          you for thirty (30) days. The Security Deposit does not activate the rental term. The rental term begins
          only when you pick up the Property, at which time your first monthly Rental Payment will be collected.
          You acknowledge that you received a completed copy of this Agreement for review before signing, with no
          blank terms to be filled in.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>19. Additional Funds Received.</strong> If you pay us any amounts in addition to your Rental
          Payment (&quot;Additional Funds&quot;), they will be applied to any amounts previously accrued, then to
          your EPO unless otherwise instructed. If the Property is returned without purchase, we will refund any
          remaining Additional Funds within 30 days upon request.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
          <strong>20. Written Receipts.</strong> We will give you a written receipt for any payments made by cash or
          money order. If you do not receive a receipt from us, please contact us immediately.
        </p>
        <Initials name={signedName || customerName} date={signedDate} />
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-6 print:break-inside-avoid print:break-before-page">
        <h2 className="font-heading mb-2 border-b-2 border-neutral-900 pb-3 text-base font-bold uppercase tracking-wide text-neutral-900 print:break-after-avoid">
          21. Jury trial waiver &amp; arbitration clause
        </h2>
        <p className="text-xs italic text-neutral-400 print:break-inside-avoid">
          By signing below (including if electronically), you agree to this Jury Trial Waiver &amp; Arbitration
          Clause (&quot;Clause&quot;).
        </p>
        <div className="mt-3">
          <ArbitrationRow q="What is arbitration?" a='An alternative to court. A third-party arbitrator ("Arbiter") solves Disputes in a hearing. You, related third parties, and we, waive the right to go to court, other than small-claims court, and forgo jury trials.' />
          <ArbitrationRow q="Is it different from court and jury trials?" a="Yes. The hearing is private and less formal than court. The decision is final; courts rarely overturn Arbiters." />
          <ArbitrationRow q="Who does the Clause cover?" a="You, Us, and Others, the parties, their heirs, successors, assigns, and third parties related to any Dispute." />
          <ArbitrationRow q="Which Disputes are covered?" a="All Disputes, including claims related to your application, nonpayment, reclaiming rented items, privacy, and this Clause's own validity and scope." />
          <ArbitrationRow q="Are you waiving rights?" a="Yes, to have juries or courts (other than small-claims) solve Disputes, to serve as a private attorney general or in a representative capacity, and to be in a class action." />
          <ArbitrationRow q="Are you waiving class action rights?" a="Yes. Only individual arbitration, or small-claims courts, will solve Disputes. If a court invalidates this waiver and that ruling can't be appealed, only a judge (not a jury) will resolve the dispute." />
          <ArbitrationRow q="What law applies?" a='The Federal Arbitration Act ("FAA"). If a court finds the FAA doesn&apos;t apply and that finding can&apos;t be appealed, your state&apos;s law governs.' />
          <ArbitrationRow q="Can the parties try to solve Disputes first?" a="Yes. Call us or mail written notice within 100 days of the Dispute. If we make a Settlement Offer, you can reject it and arbitrate." />
          <ArbitrationRow q="How should you contact us?" a={contactUsAnswer} />
          <ArbitrationRow q="Can small-claims court solve some Disputes?" a="Yes, where it has the power to hear the Dispute; otherwise arbitration applies." />
          <ArbitrationRow q="Do other options exist?" a="Yes, both parties may use lawful self-help remedies such as set-off, repossession, or reclaiming rented items." />
          <ArbitrationRow q="Will this Clause continue to govern?" a="Yes, unless otherwise agreed. The Clause governs if you rescind, cancel, terminate, default, renew, prepay, or pay, or if your contract is discharged through bankruptcy." />
          <ArbitrationRow q="How does arbitration start?" a="By mailing a notice. The receiving party must mail a response within 20 days." />
          <ArbitrationRow q="Who arbitrates?" a="AAA, JAMS, or an agreed Arbiter, under AAA or JAMS consumer rules." />
          <ArbitrationRow q="Will the hearing be held nearby?" a="Yes, within 30 miles of your home or where the transaction occurred." />
          <ArbitrationRow q="What about appeals?" a="Limited. If the amount in controversy exceeds $10,000, either party may appeal to a three-Arbiter panel, decided de novo; the appealing party bears appeal costs regardless of outcome." />
          <ArbitrationRow q="Will we advance Arbitration Fees?" a="Yes, but you may pay costs. We advance filing, administrative, hearing, and Arbiter's fees; you pay your own attorney fees." />
          <ArbitrationRow q="Are damages and attorney fees possible?" a="Yes, if allowed. The Arbiter may award the same damages as a court, plus reasonable attorney fees and expenses, if allowed by law." />
          <ArbitrationRow q="Will you pay Arbitration Fees if you win?" a="No." />
          <ArbitrationRow q="Will you ever pay Arbitration Fees?" a="In some cases, if the Arbiter doesn't award you funds, capped at state court costs." />
          <ArbitrationRow q="What happens if you win?" a="You could get more than the Arbiter awarded: if your award exceeds our last Settlement Offer, we pay the greater of the award or $2,500, plus your attorney fees plus 25%, plus certain expert-witness and cost premiums." />
          <ArbitrationRow q="Can an award be explained?" a="Yes, a party may request details from the Arbiter within 14 days of the ruling." />
          <ArbitrationRow q="If you don't want to arbitrate, can you still get a transaction?" a="Yes, through informal dispute resolution, small-claims court, requesting a contract without this Clause, or a timely opt-out after signing." />
          <ArbitrationRow q="Can you opt out of the Clause?" a='Yes, within 60 calendar days of signing, by writing to us with your name, address, account number, date, and that you "opt out."' />
        </div>
        <Initials name={signedName || customerName} date={signedDate} />
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-6 print:break-inside-avoid print:break-before-page">
        <h2 className="font-heading mb-2 border-b-2 border-neutral-900 pb-3 text-base font-bold uppercase tracking-wide text-neutral-900 print:break-after-avoid">
          Product info &amp; early purchase option
        </h2>

        <div className="mt-4 grid grid-cols-1 gap-x-8 sm:grid-cols-2">
          <Row label="Dealer" value="Prostart Leasing" />
          <Row label="Cash Price" value={money(cashPrice)} />
          <Row label="Tax Rate" value={`${taxRatePct}%`} />
          <Row label="LDW" value={lease.ldw_selected ? "Yes" : "No"} />
          <Row label="Total Paying Today" value={money(totalDueToday)} />
          <Row label="Promo" value={lease.promo_code ?? "—"} />
        </div>
        <p className="mt-3 text-xs italic text-neutral-400 print:break-inside-avoid">
          *Additional funds paid over required deposit, first payment and other fees will reduce monthly payment.
        </p>
        <div className="print:break-inside-avoid">
          <SignatureBlock name={signedName || customerName} date={signedDate} status="Signed & legally valid" />
          <p className="mt-3 text-xs italic leading-relaxed text-neutral-400">
            By signing above, you agree to the Lease Purchase Agreement in full, including the Jury Trial Waiver
            &amp; Arbitration Clause above.
          </p>
        </div>
      </div>

      {lease.autopay_enabled && (
        <div className="rounded-xl border border-neutral-200 bg-white p-6 print:break-inside-avoid print:break-before-page">
          <h2 className="font-heading mb-2 border-b-2 border-neutral-900 pb-3 text-base font-bold uppercase tracking-wide text-neutral-900 print:break-after-avoid">
            Autopay lease payment authorization
          </h2>
          <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            By choosing to sign below, you elect to make your monthly lease payments automatically as set forth in
            this Agreement, using the bank account or payment method you verified and connected during your
            application.
          </p>
          <div className="mt-4 grid grid-cols-1 gap-x-8 sm:grid-cols-2">
            <Row label="Payment Amount" value={money(totalMonthly)} />
            <Row label="Payment Due Day" value={lease.payment_due_day ?? "—"} />
            <Row label="Payment Frequency" value="Monthly" />
          </div>
          <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            <strong>Payment Dates &amp; Notice of Variation.</strong> We will initiate payments from your verified
            payment method on or after each Payment Due Date, in the amount described above, plus any accrued but
            unpaid rental charges, up to a maximum of $30.00 more than your regularly-scheduled payment amount. You
            will receive notice at least 10 days before a payment is deducted if it falls outside that range.
          </p>
          <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            <strong>Revocation.</strong> This Payment Authorization applies until you revoke it. You may revoke it by
            notifying us in writing at least 3 business days before a scheduled payment. Revoking this authorization
            does not relieve you of your obligation to make Rental Payments by another method.
          </p>
          <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            <strong>Acknowledgment.</strong> By signing below, you agree to the terms of this Payment Authorization
            and acknowledge that you were not required to sign it to obtain this lease; you may arrange payment via
            an alternative method at any time.
          </p>
          <SignatureBlock name={signedName || customerName} date={signedDate} status="Autopay authorized" />
        </div>
      )}

      {equipment?.gps_device_id && (
        <div className="rounded-xl border border-neutral-200 bg-white p-6 print:break-inside-avoid print:break-before-page">
          <h2 className="font-heading mb-2 border-b-2 border-neutral-900 pb-3 text-base font-bold uppercase tracking-wide text-neutral-900 print:break-after-avoid">
            GPS disclosure statement &amp; agreement
          </h2>
          <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            The Property you are leasing is equipped with a GPS device (the &quot;Device&quot;). You acknowledge that
            you are free to lease or purchase a property from another dealer, or obtain a property through another
            source, that does not require installation of a GPS device instead of leasing this Property from us.
          </p>
          <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            You understand that if you fail to make your renewal payments or fail to return the Property by the
            required date, you will be considered in default under this Agreement. In that event, we may use the
            Device to locate the Property for repossession and any other purpose not prohibited by law. The GPS will
            not be used to monitor your habits or practices, but may be activated to confirm the Device is
            functioning and that the Property has not been moved from the address you provided or otherwise approved
            by us. We will not provide access to the Device&apos;s tracking record unless required by law or to
            enforce our rights under this Agreement.
          </p>
          <p className="mt-3 text-xs italic leading-relaxed text-neutral-400 print:break-inside-avoid">
            If you alter, tamper with, disconnect, or remove the Device, you will be in default under this Agreement.
            You may be liable for the cost to repair or replace the Device, unless prohibited by law, and you agree
            to give us access to the Property if maintenance or repairs to the Device are necessary. By signing
            below, you acknowledge that you have read, accept, and understand the terms of this GPS Disclosure
            Statement, and that it is incorporated into and becomes part of this Agreement.
          </p>
          <SignatureBlock name={signedName || customerName} date={signedDate} status="GPS disclosure acknowledged" />
        </div>
      )}

      {lease.epo_schedule && (
        <div className="rounded-xl border border-neutral-200 bg-white p-6 print:break-inside-avoid print:break-before-page">
          <SubHeader>Early purchase option chart</SubHeader>
          <p className="mt-2 text-xs text-neutral-400">
            EPO price after each rental renewal payment, assuming on-time payments. Excludes tax.
          </p>
          <div className="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-3 lg:grid-cols-6 print:grid-cols-6">
            {lease.epo_schedule
              .filter((row) => row.month === 1 || row.month % 3 === 0)
              .map((row) => (
                <div key={row.month} className="flex items-center justify-between border-b border-neutral-100 py-1.5">
                  <span className="text-neutral-400">{row.month}</span>
                  <span className="font-semibold text-neutral-800">{row.value.toFixed(2)}</span>
                </div>
              ))}
          </div>
        </div>
      )}
    </div>
  );
}
