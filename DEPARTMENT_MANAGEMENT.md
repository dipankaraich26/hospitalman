# 🏥 Department Management System

## Overview
Complete department management system for hospital organizational structure. Allows administrators to create, edit, and manage all hospital departments with real-time staff and doctor tracking.

---

## 📋 Features

### 1. **Department CRUD Operations**
- ✅ **Add New Department** - Create departments with name, description, and status
- ✅ **Edit Department** - Update department details
- ✅ **Toggle Status** - Activate/deactivate departments
- ✅ **Unique Validation** - Prevents duplicate department names

### 2. **Real-time Statistics Dashboard**
- **Total Departments** - Count of all departments
- **Active Departments** - Currently active departments
- **Total Doctors** - Doctors assigned across all departments
- **Total Staff** - Staff members assigned to departments

### 3. **Department Details Table**
Each department shows:
- Department ID
- Department Name
- Description
- Doctor Count (badge)
- Staff Count (badge)
- Status (Active/Inactive)
- Creation Date
- Action Buttons (Edit, Toggle Status)

### 4. **Advanced Features**
- 🔍 **DataTable Integration** - Search, sort, and paginate departments
- 📊 **Staff Tracking** - Automatic counting of doctors and staff per department
- 🎨 **Color-Coded Status** - Visual indicators (Green: Active, Red: Inactive)
- ⚡ **Modal Dialogs** - Quick add/edit without page reload
- 🔒 **Admin Only Access** - Only administrators can manage departments

---

## 🎯 Use Cases

### **Scenario 1: Hospital Setup**
**Task:** Set up organizational structure for a new hospital branch

**Steps:**
1. Navigate to **Staff Management → Departments**
2. Click **Add Department** button
3. Create core departments:
   - General Medicine
   - Emergency
   - Surgery
   - Radiology
   - Pathology
4. Add specialized departments as needed

### **Scenario 2: Department Reorganization**
**Task:** Merge two departments or split one into multiple

**Steps:**
1. Go to **Departments** page
2. Click **Edit** on department to modify
3. Update name and description
4. Or deactivate old department and create new ones

### **Scenario 3: Temporary Closure**
**Task:** Temporarily close a department for renovation

**Steps:**
1. Find department in table
2. Click **Toggle Status** button (ban icon)
3. Status changes to "Inactive"
4. Department hidden from active selections
5. Re-activate when ready

---

## 💻 Technical Implementation

### **Database Schema**
```sql
CREATE TABLE departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) UNIQUE NOT NULL,
  description TEXT,
  status ENUM('active', 'inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### **Files Created**
| File | Purpose |
|------|---------|
| `modules/staff/departments.php` | Main department management page |
| `sql/departments_table.sql` | Database schema + sample data |
| `DEPARTMENT_MANAGEMENT.md` | Documentation |

### **Files Modified**
| File | Changes |
|------|---------|
| `includes/header.php` | Added "Departments" link in Staff Management menu |

### **Relationships**
- **staff_info.department_id** → departments.id
- **Cascade Effect:** Department changes reflect in:
  - Doctor Departments page
  - OPD Calendar department filter
  - Staff assignments
  - Analytics by department

---

## 🔐 Security Features

1. **Admin-Only Access**
   ```php
   if (!isLoggedIn() || $_SESSION['role'] !== 'admin') {
       header('Location: login.php');
       exit;
   }
   ```

2. **Unique Name Validation**
   - Prevents duplicate department names
   - Case-sensitive checking
   - Shows error message on conflict

3. **SQL Injection Prevention**
   - All queries use PDO prepared statements
   - Input sanitization with htmlspecialchars()

4. **Status Toggle Confirmation**
   - JavaScript confirmation dialog before status change
   - Prevents accidental deactivation

---

## 📊 Statistics Calculation

### **Doctor Count Per Department**
```sql
SELECT COUNT(DISTINCT si.user_id) as doctor_count
FROM staff_info si
JOIN users u ON si.user_id = u.id
WHERE si.department_id = ? AND u.role = 'doctor'
```

### **Staff Count Per Department**
```sql
SELECT COUNT(DISTINCT si.user_id) as staff_count
FROM staff_info si
JOIN users u ON si.user_id = u.id
WHERE si.department_id = ? AND u.role != 'doctor'
```

### **Department Load (Appointments)**
```sql
SELECT COUNT(a.id) as appointment_count
FROM appointments a
JOIN staff_info si ON a.doctor_id = si.user_id
WHERE si.department_id = ? AND MONTH(a.appointment_date) = MONTH(CURDATE())
```

---

## 🎨 UI Components

### **Statistics Cards**
```html
<div class="card bg-primary text-white">
  <div class="card-body">
    <h6>Total Departments</h6>
    <h3>15</h3>
    <i class="fas fa-building"></i>
  </div>
</div>
```

### **Action Buttons**
- **Edit (Blue):** Opens edit modal with pre-filled data
- **Toggle Status (Yellow/Green):** Activates/deactivates department

### **Status Badges**
- **Active:** Green badge
- **Inactive:** Red badge

---

## 📱 Responsive Design

### **Desktop View (≥768px)**
- Full DataTable with all columns
- Horizontal layout for statistics cards
- Modal dialogs for add/edit

### **Mobile View (<768px)**
- Card-based department list
- Stacked statistics cards
- Touch-friendly buttons
- Responsive table scrolling

---

## 🚀 Sample Departments (Pre-loaded)

The system comes with 15 common hospital departments:

1. **Cardiology** - Heart and cardiovascular system
2. **Neurology** - Nervous system disorders
3. **Pediatrics** - Children's healthcare
4. **Orthopedics** - Bone and muscle conditions
5. **Gynecology** - Women's reproductive health
6. **Dermatology** - Skin conditions
7. **Ophthalmology** - Eye care
8. **ENT** - Ear, Nose, and Throat
9. **General Medicine** - Primary care
10. **Surgery** - Surgical procedures
11. **Radiology** - Medical imaging
12. **Pathology** - Laboratory diagnostics
13. **Emergency** - Emergency care
14. **Psychiatry** - Mental health
15. **Oncology** - Cancer treatment

---

## 🔄 Integration with Other Modules

### **OPD Calendar**
- Department filter in calendar view
- Shows department-wise appointment load
- Color-coded by department

### **Doctor Departments**
- Assigns doctors to departments
- Dropdown populated from active departments
- Shows current assignment

### **Staff Management**
- Links staff to departments
- Department-based access control
- Performance tracking by department

### **Analytics**
- Department profitability analysis
- Revenue per department
- Patient distribution by department

---

## ✅ Validation Rules

### **Department Name**
- ✅ Required field
- ✅ Must be unique
- ✅ Max 100 characters
- ✅ Trimmed whitespace

### **Description**
- ⭕ Optional field
- ✅ Text area (up to 65535 characters)
- ✅ Supports line breaks

### **Status**
- ✅ Must be 'active' or 'inactive'
- ✅ Defaults to 'active' for new departments

---

## 🐛 Error Handling

### **Duplicate Name Error**
```
Error: Department with this name already exists
```
**Solution:** Choose a different name or edit existing department

### **Empty Name Error**
```
Error: Department name is required
```
**Solution:** Fill in the department name field

### **Database Connection Error**
```
Error: Unable to connect to database
```
**Solution:** Check database credentials in config.php

---

## 📈 Future Enhancements (Optional)

1. **Department Hierarchy**
   - Parent-child relationships
   - Sub-departments (e.g., Cardiology → Cardiac Surgery)

2. **Department Head Assignment**
   - Assign a doctor as department head
   - Head can manage department settings

3. **Department Budget**
   - Budget allocation per department
   - Expense tracking
   - Budget vs actual reporting

4. **Department Resources**
   - Equipment assignment
   - Room allocation
   - Bed capacity

5. **Department Performance**
   - KPIs per department
   - Patient satisfaction scores
   - Revenue contribution

6. **Department Schedule**
   - Working hours per department
   - Holiday schedules
   - On-call rosters

---

## 🎓 Best Practices

1. **Naming Convention**
   - Use standard medical department names
   - Avoid abbreviations unless common (ENT, ICU)
   - Use proper capitalization

2. **Status Management**
   - Only deactivate departments when truly inactive
   - Re-assign doctors before deactivating
   - Document reason for deactivation

3. **Data Consistency**
   - Verify no active doctors/staff before deletion
   - Update related records when renaming
   - Keep descriptions up to date

4. **Regular Review**
   - Quarterly review of active departments
   - Update descriptions as services evolve
   - Archive historical data

---

## 📞 Support

For issues or questions:
- Check error messages in the UI
- Verify database structure matches schema
- Ensure admin permissions are set
- Review browser console for JavaScript errors

---

## 🎉 Summary

The Department Management System provides:
✅ Complete CRUD operations for departments
✅ Real-time statistics and tracking
✅ Integration with staff and doctor management
✅ Admin-only secure access
✅ Responsive design for all devices
✅ DataTable for easy searching and sorting

**Navigation:** Staff Management → Departments
**Access:** Admin users only
**Status:** Production-ready ✅
