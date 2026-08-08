// Seeds only the login account — no sample leads or activities. Run with
// `npm run db:seed`. Safe to re-run: it wipes existing Lead/Activity/User
// rows first, so only ever run this against a database you're happy to
// start fresh (see README for the production reset procedure).

import "dotenv/config";
import { PrismaPg } from "@prisma/adapter-pg";
import { PrismaClient } from "../src/generated/prisma/client";
import { hashPassword } from "../src/lib/password";

const adapter = new PrismaPg({ connectionString: process.env.DATABASE_URL });
const prisma = new PrismaClient({ adapter });

async function main() {
  console.log("Seeding database...");

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

  const leadCount = await prisma.lead.count();
  const activityCount = await prisma.activity.count();
  console.log(`Database now has ${leadCount} leads and ${activityCount} activities.`);
}

main()
  .catch((err) => {
    console.error(err);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
