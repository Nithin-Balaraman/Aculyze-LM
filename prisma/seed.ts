// Seeds a small set of realistic sample leads/activities so the app is
// immediately explorable. Run with `npm run db:seed`.
//
// This script talks to Prisma directly and re-implements (in miniature)
// the same two rules the real app enforces in src/lib/actions.ts:
//   1. leadId / activityId are derived from the row's real `id` right
//      after insert.
//   2. 3+ unanswered-call Activities auto-escalate a Lead to High priority.
// That's intentional duplication, not a shortcut — a seed script is a
// one-off bootstrap, not a code path worth sharing with production logic.

import "dotenv/config";
import { PrismaPg } from "@prisma/adapter-pg";
import { PrismaClient } from "../src/generated/prisma/client";
import {
  ActivityType,
  CallOutcome,
  ContactChannel,
  LeadStatus,
  Priority,
} from "../src/generated/prisma/enums";
import { formatActivityId, formatLeadId } from "../src/lib/ids";
import { hashPassword } from "../src/lib/password";

const adapter = new PrismaPg({ connectionString: process.env.DATABASE_URL });
const prisma = new PrismaClient({ adapter });

function daysAgo(n: number): Date {
  const d = new Date();
  d.setDate(d.getDate() - n);
  return d;
}

function daysFromNow(n: number): Date {
  return daysAgo(-n);
}

type LeadSeed = {
  companyName: string;
  industry?: string;
  city?: string;
  fullLocation?: string;
  contactNumber?: string;
  contactPerson?: string;
  email?: string;
  mobileNumber?: string;
  website?: string;
  assignedOwner?: string;
  dataQualityNote?: string;
};

async function createLead(seed: LeadSeed) {
  const created = await prisma.lead.create({
    data: { ...seed, leadId: "PENDING" },
  });
  return prisma.lead.update({
    where: { id: created.id },
    data: { leadId: formatLeadId(created.id) },
  });
}

type ActivitySeed = {
  leadDbId: number;
  activityDate: Date;
  activityType: ActivityType;
  contactChannel: ContactChannel;
  callOutcome: CallOutcome;
  detailsRemarks: string;
  leadStatus: LeadStatus;
  nextAction?: string;
  followUpDate?: Date;
  assignedOwner?: string;
};

async function createActivity(seed: ActivitySeed) {
  const { leadDbId, ...rest } = seed;
  const created = await prisma.activity.create({
    data: { ...rest, leadId: leadDbId, activityId: "PENDING" },
  });
  return prisma.activity.update({
    where: { id: created.id },
    data: { activityId: formatActivityId(created.id) },
  });
}

async function main() {
  console.log("Seeding database...");

  // Wipe existing data so this script is safely re-runnable.
  await prisma.activity.deleteMany();
  await prisma.lead.deleteMany();
  await prisma.user.deleteMany();

  await prisma.user.upsert({
    where: { name: "Nithin" },
    update: {},
    create: {
      name: "Nithin",
      passwordHash: await hashPassword("aculyze2026"),
    },
  });
  console.log('Seeded login: name "Nithin", password "aculyze2026"');

  // --- 1. Sundaram Textiles — working, appointment confirmed ---
  const sundaram = await createLead({
    companyName: "Sundaram Textiles Pvt Ltd",
    industry: "Textiles & Manufacturing",
    city: "Coimbatore",
    fullLocation: "Sundaram Textiles Pvt Ltd, Avinashi Road, Coimbatore, TN",
    contactNumber: "0422-2345678",
    contactPerson: "R. Balasubramanian",
    email: "balu@sundaramtextiles.example.com",
    mobileNumber: "9843012345",
    website: "https://sundaramtextiles.example.com",
    assignedOwner: "Nithin",
  });
  await createActivity({
    leadDbId: sundaram.id,
    activityDate: daysAgo(9),
    activityType: ActivityType.ColdCall,
    contactChannel: ContactChannel.Phone,
    callOutcome: CallOutcome.Connected,
    detailsRemarks: "Interested in ERP + BI dashboard for production tracking.",
    leadStatus: LeadStatus.Contacted,
    nextAction: "Send capability deck",
    followUpDate: daysAgo(6),
    assignedOwner: "Nithin",
  });
  await createActivity({
    leadDbId: sundaram.id,
    activityDate: daysAgo(6),
    activityType: ActivityType.FollowUp,
    contactChannel: ContactChannel.Email,
    callOutcome: CallOutcome.Connected,
    detailsRemarks: "Deck sent, they want a demo with their ops manager.",
    leadStatus: LeadStatus.Working,
    nextAction: "Confirm demo meeting",
    followUpDate: daysAgo(1),
    assignedOwner: "Nithin",
  });
  const sundaramLatest = await createActivity({
    leadDbId: sundaram.id,
    activityDate: daysAgo(1),
    activityType: ActivityType.Appointment,
    contactChannel: ContactChannel.InPerson,
    callOutcome: CallOutcome.AppointmentConfirmed,
    detailsRemarks: "Demo confirmed on-site with ops manager and IT lead.",
    leadStatus: LeadStatus.Working,
    nextAction: "Deliver ERP + BI demo",
    followUpDate: daysFromNow(3),
    assignedOwner: "Nithin",
  });
  await prisma.lead.update({
    where: { id: sundaram.id },
    data: {
      leadStatus: sundaramLatest.leadStatus,
      nextAction: sundaramLatest.nextAction,
      followUpDate: sundaramLatest.followUpDate,
      priority: Priority.Medium,
    },
  });

  // --- 2. KG Hospitals — high priority, overdue follow-up ---
  const kgHospitals = await createLead({
    companyName: "KG Hospitals",
    industry: "Healthcare",
    city: "Coimbatore",
    fullLocation: "KG Hospitals, Arts College Road, Coimbatore, TN",
    contactNumber: "0422-4567890",
    contactPerson: "Dr. Meenakshi Sundaram",
    email: "meenakshi@kghospitals.example.com",
    mobileNumber: "9944012345",
    assignedOwner: "Nithin",
  });
  await createActivity({
    leadDbId: kgHospitals.id,
    activityDate: daysAgo(12),
    activityType: ActivityType.ColdCall,
    contactChannel: ContactChannel.Phone,
    callOutcome: CallOutcome.Connected,
    detailsRemarks: "Evaluating cybersecurity audit + Microsoft 365 migration.",
    leadStatus: LeadStatus.Contacted,
    nextAction: "Send security audit proposal",
    followUpDate: daysAgo(8),
    assignedOwner: "Nithin",
  });
  const kgLatest = await createActivity({
    leadDbId: kgHospitals.id,
    activityDate: daysAgo(8),
    activityType: ActivityType.FollowUp,
    contactChannel: ContactChannel.Phone,
    callOutcome: CallOutcome.CallbackRequested,
    detailsRemarks: "Asked us to call back after their board review — was due days ago.",
    leadStatus: LeadStatus.Contacted,
    nextAction: "Call back re: board decision",
    followUpDate: daysAgo(3),
    assignedOwner: "Nithin",
  });
  await prisma.lead.update({
    where: { id: kgHospitals.id },
    data: {
      leadStatus: kgLatest.leadStatus,
      nextAction: kgLatest.nextAction,
      followUpDate: kgLatest.followUpDate,
      priority: Priority.High, // manually flagged urgent — big potential account
    },
  });

  // --- 3. Sri Ram Autocomponents — 3 unanswered calls, auto-escalated ---
  const sriRam = await createLead({
    companyName: "Sri Ram Autocomponents",
    industry: "Automotive Components",
    city: "Coimbatore",
    fullLocation: "Sri Ram Autocomponents, Kurichi, Coimbatore, TN",
    contactNumber: "0422-3456789",
    contactPerson: "V. Krishnamurthy",
    mobileNumber: "9865043210",
    assignedOwner: "Nithin",
  });
  for (const n of [10, 6, 2]) {
    await createActivity({
      leadDbId: sriRam.id,
      activityDate: daysAgo(n),
      activityType: ActivityType.ColdCall,
      contactChannel: ContactChannel.Phone,
      callOutcome: CallOutcome.NoAnswer,
      detailsRemarks: "No answer, tried their listed landline.",
      leadStatus: LeadStatus.New,
      assignedOwner: "Nithin",
    });
  }
  // 3rd unanswered attempt crosses the auto-escalation threshold (see
  // checkUnansweredEscalation() in src/lib/actions.ts for the real path).
  await prisma.lead.update({
    where: { id: sriRam.id },
    data: { priority: Priority.High },
  });

  // --- 4 & 5. Coimbatore Weaving Mills — data quality issues, incl. a
  // pre-existing duplicate company name (the kind of mess this app is
  // meant to catch after years of spreadsheet hand-entry). ---
  await createLead({
    companyName: "Coimbatore Weaving Mills",
    industry: "Textiles",
    city: "Coimbatore",
    contactNumber: "0422-2223344",
    // contactPerson intentionally missing
    email: "info@weavingmills", // malformed — no "."
    dataQualityNote: "Inherited from old Excel sheet, contact name never captured.",
  });
  await createLead({
    companyName: "COIMBATORE WEAVING MILLS", // same company, different casing
    industry: "Textiles",
    city: "Coimbatore",
    contactPerson: "S. Palanisamy",
    email: "palanisamy@weavingmills.example.com",
    mobileNumber: "9842011223",
    dataQualityNote: "Possible duplicate of an earlier entry — needs merging.",
  });

  // --- 6. Erode Foods & Beverages — Won, should archive out of live views ---
  const erode = await createLead({
    companyName: "Erode Foods & Beverages",
    industry: "Food & Beverage",
    city: "Erode",
    fullLocation: "Erode Foods & Beverages, Perundurai Road, Erode, TN",
    contactNumber: "0424-2334455",
    contactPerson: "N. Rajendran",
    email: "rajendran@erodefoods.example.com",
    mobileNumber: "9843099887",
    assignedOwner: "Nithin",
  });
  await createActivity({
    leadDbId: erode.id,
    activityDate: daysAgo(30),
    activityType: ActivityType.ColdCall,
    contactChannel: ContactChannel.Phone,
    callOutcome: CallOutcome.Connected,
    detailsRemarks: "Needed cloud backup + automation for order processing.",
    leadStatus: LeadStatus.Qualified,
    assignedOwner: "Nithin",
  });
  const erodeLatest = await createActivity({
    leadDbId: erode.id,
    activityDate: daysAgo(15),
    activityType: ActivityType.Email,
    contactChannel: ContactChannel.Email,
    callOutcome: CallOutcome.Connected,
    detailsRemarks: "Signed off on the automation + cloud backup package.",
    leadStatus: LeadStatus.Won,
    assignedOwner: "Nithin",
  });
  await prisma.lead.update({
    where: { id: erode.id },
    data: { leadStatus: erodeLatest.leadStatus, priority: Priority.Low },
  });

  // --- 7. Tirupur Garments Exports — Lost, should also archive out ---
  const tirupur = await createLead({
    companyName: "Tirupur Garments Exports",
    industry: "Garments & Textiles",
    city: "Tirupur",
    contactNumber: "0421-2245566",
    contactPerson: "K. Muthu",
    mobileNumber: "9865011223",
    assignedOwner: "Nithin",
  });
  await createActivity({
    leadDbId: tirupur.id,
    activityDate: daysAgo(20),
    activityType: ActivityType.ColdCall,
    contactChannel: ContactChannel.Phone,
    callOutcome: CallOutcome.Connected,
    detailsRemarks: "Explored ERP for export order tracking.",
    leadStatus: LeadStatus.Contacted,
    assignedOwner: "Nithin",
  });
  const tirupurLatest = await createActivity({
    leadDbId: tirupur.id,
    activityDate: daysAgo(14),
    activityType: ActivityType.FollowUp,
    contactChannel: ContactChannel.Phone,
    callOutcome: CallOutcome.NotInterested,
    detailsRemarks: "Went with an in-house solution instead.",
    leadStatus: LeadStatus.Lost,
    assignedOwner: "Nithin",
  });
  await prisma.lead.update({
    where: { id: tirupur.id },
    data: { leadStatus: tirupurLatest.leadStatus, priority: Priority.Low },
  });

  // --- 8. Madurai Solar Energy — Nurture, upcoming (not overdue) follow-up ---
  const madurai = await createLead({
    companyName: "Madurai Solar Energy",
    industry: "Renewable Energy",
    city: "Madurai",
    contactNumber: "0452-2334411",
    contactPerson: "P. Chidambaram",
    email: "chidambaram@maduraisolar.example.com",
    mobileNumber: "9843055667",
    assignedOwner: "Nithin",
  });
  const maduraiLatest = await createActivity({
    leadDbId: madurai.id,
    activityDate: daysAgo(4),
    activityType: ActivityType.ColdCall,
    contactChannel: ContactChannel.Phone,
    callOutcome: CallOutcome.Connected,
    detailsRemarks: "Not ready to invest this quarter — check back next month.",
    leadStatus: LeadStatus.Nurture,
    nextAction: "Check in on next quarter's budget",
    followUpDate: daysFromNow(10),
    assignedOwner: "Nithin",
  });
  await prisma.lead.update({
    where: { id: madurai.id },
    data: {
      leadStatus: maduraiLatest.leadStatus,
      nextAction: maduraiLatest.nextAction,
      followUpDate: maduraiLatest.followUpDate,
    },
  });

  // --- 9. Salem Pumps & Motors — stale, no activity in 10 days, no next action ---
  const salem = await createLead({
    companyName: "Salem Pumps & Motors",
    industry: "Industrial Manufacturing",
    city: "Salem",
    contactNumber: "0427-2233445",
    contactPerson: "A. Selvam",
    mobileNumber: "9843066778",
    assignedOwner: "Nithin",
  });
  const salemLatest = await createActivity({
    leadDbId: salem.id,
    activityDate: daysAgo(10),
    activityType: ActivityType.ColdCall,
    contactChannel: ContactChannel.Phone,
    callOutcome: CallOutcome.Connected,
    detailsRemarks: "Brief chat, said to follow up but nothing scheduled.",
    leadStatus: LeadStatus.New,
    assignedOwner: "Nithin",
  });
  await prisma.lead.update({
    where: { id: salem.id },
    data: { leadStatus: salemLatest.leadStatus },
  });

  // --- 10. Ooty Spices Traders — data quality: no phone AND no mobile ---
  await createLead({
    companyName: "Ooty Spices Traders",
    industry: "Agro Trading",
    city: "Ooty",
    contactPerson: "T. Shanmugam",
    email: "shanmugam@ootyspices.example.com",
    dataQualityNote: "Only reachable via email so far — no phone on file.",
  });

  // --- 11. Nilgiris EdTech Solutions — qualified, upcoming follow-up ---
  const nilgiris = await createLead({
    companyName: "Nilgiris EdTech Solutions",
    industry: "Education Technology",
    city: "Ooty",
    contactNumber: "0423-2445566",
    contactPerson: "L. Priya",
    email: "priya@nilgirisedtech.example.com",
    mobileNumber: "9843077889",
    website: "https://nilgirisedtech.example.com",
    assignedOwner: "Nithin",
  });
  const nilgirisLatest = await createActivity({
    leadDbId: nilgiris.id,
    activityDate: daysAgo(2),
    activityType: ActivityType.FollowUp,
    contactChannel: ContactChannel.WhatsApp,
    callOutcome: CallOutcome.Connected,
    detailsRemarks: "Wants a proposal for their student management + AI chatbot idea.",
    leadStatus: LeadStatus.Qualified,
    nextAction: "Send proposal for student management + AI chatbot",
    followUpDate: daysFromNow(2),
    assignedOwner: "Nithin",
  });
  await prisma.lead.update({
    where: { id: nilgiris.id },
    data: {
      leadStatus: nilgirisLatest.leadStatus,
      nextAction: nilgirisLatest.nextAction,
      followUpDate: nilgirisLatest.followUpDate,
    },
  });

  const leadCount = await prisma.lead.count();
  const activityCount = await prisma.activity.count();
  console.log(`Seeded ${leadCount} leads and ${activityCount} activities.`);
}

main()
  .catch((err) => {
    console.error(err);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
