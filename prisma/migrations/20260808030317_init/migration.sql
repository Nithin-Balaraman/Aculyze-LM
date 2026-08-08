-- CreateEnum
CREATE TYPE "LeadStatus" AS ENUM ('New', 'Contacted', 'Qualified', 'Working', 'Nurture', 'Won', 'Lost', 'Unassigned');

-- CreateEnum
CREATE TYPE "Priority" AS ENUM ('High', 'Medium', 'Low');

-- CreateEnum
CREATE TYPE "ActivityType" AS ENUM ('ColdCall', 'FollowUp', 'Appointment', 'Email', 'WhatsApp', 'Other');

-- CreateEnum
CREATE TYPE "ContactChannel" AS ENUM ('Phone', 'Email', 'WhatsApp', 'InPerson', 'Other');

-- CreateEnum
CREATE TYPE "CallOutcome" AS ENUM ('Connected', 'NoAnswer', 'Unreachable', 'AppointmentConfirmed', 'NotInterested', 'CallbackRequested', 'Other');

-- CreateTable
CREATE TABLE "User" (
    "id" SERIAL NOT NULL,
    "name" TEXT NOT NULL,
    "passwordHash" TEXT NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "User_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Lead" (
    "id" SERIAL NOT NULL,
    "leadId" TEXT NOT NULL,
    "companyName" TEXT NOT NULL,
    "industry" TEXT,
    "city" TEXT,
    "fullLocation" TEXT,
    "contactNumber" TEXT,
    "contactPerson" TEXT,
    "email" TEXT,
    "mobileNumber" TEXT,
    "website" TEXT,
    "sourceSheet" TEXT DEFAULT 'Lead Entry',
    "leadStatus" "LeadStatus" NOT NULL DEFAULT 'New',
    "priority" "Priority" NOT NULL DEFAULT 'Medium',
    "nextAction" TEXT,
    "followUpDate" TIMESTAMP(3),
    "assignedOwner" TEXT,
    "dataQualityNote" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "Lead_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Activity" (
    "id" SERIAL NOT NULL,
    "activityId" TEXT NOT NULL,
    "leadId" INTEGER NOT NULL,
    "activityDate" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "activityType" "ActivityType" NOT NULL,
    "contactChannel" "ContactChannel" NOT NULL,
    "callOutcome" "CallOutcome" NOT NULL,
    "detailsRemarks" TEXT,
    "nextAction" TEXT,
    "followUpDate" TIMESTAMP(3),
    "leadStatus" "LeadStatus" NOT NULL,
    "assignedOwner" TEXT,
    "sourceSheet" TEXT DEFAULT 'Lead Entry',
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "Activity_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "User_name_key" ON "User"("name");

-- CreateIndex
CREATE UNIQUE INDEX "Lead_leadId_key" ON "Lead"("leadId");

-- CreateIndex
CREATE INDEX "Lead_companyName_idx" ON "Lead"("companyName");

-- CreateIndex
CREATE INDEX "Lead_leadStatus_idx" ON "Lead"("leadStatus");

-- CreateIndex
CREATE INDEX "Lead_priority_idx" ON "Lead"("priority");

-- CreateIndex
CREATE INDEX "Lead_followUpDate_idx" ON "Lead"("followUpDate");

-- CreateIndex
CREATE UNIQUE INDEX "Activity_activityId_key" ON "Activity"("activityId");

-- CreateIndex
CREATE INDEX "Activity_leadId_activityDate_idx" ON "Activity"("leadId", "activityDate");

-- CreateIndex
CREATE INDEX "Activity_callOutcome_idx" ON "Activity"("callOutcome");

-- AddForeignKey
ALTER TABLE "Activity" ADD CONSTRAINT "Activity_leadId_fkey" FOREIGN KEY ("leadId") REFERENCES "Lead"("id") ON DELETE CASCADE ON UPDATE CASCADE;
